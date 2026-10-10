<?php
/**
 * CounterLogic POS - webERP Synchronization Backend API
 * Clean and sanitized version for public release.
 */

ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Ubah ke 0 saat production

$nama_file_koneksi = 'config.php'; 
$db_config_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $nama_file_koneksi;

if (file_exists($db_config_path)) {
    require_once $db_config_path;
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'File koneksi database di root tidak ditemukan.']);
    exit;
}

// Pastikan variabel $pdo tersedia dari file koneksi root
if (!isset($pdo)) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'Variabel koneksi $pdo tidak terdefinisi di file root.']);
    exit;
}

// --- VALIDASI TOKEN KEAMANAN (HMAC-SHA256) ---
$secret_db_name = isset($dbname) ? $dbname : 'default_erp_db';
$message = "pos_client_sync";
$expected_token = hash_hmac('sha256', $message, $secret_db_name);

$headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
$client_token = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : (isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '');

if (empty($client_token) || !hash_equals($expected_token, $client_token)) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(401);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Unauthorized: Token keamanan API tidak valid atau tidak disertakan.'
    ]);
    exit;
}
// ----------------------------------------------

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'download_full';
$limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 1000;
$page = isset($_REQUEST['page']) ? (int)$_REQUEST['page'] : 1;
$offset = ($page - 1) * $limit;
$loccode = isset($_REQUEST['loccode']) ? $_REQUEST['loccode'] : 'PST';
$debtorno = isset($_REQUEST['debtorno']) ? $_REQUEST['debtorno'] : '';
$branchcode = isset($_REQUEST['branchcode']) ? $_REQUEST['branchcode'] : '';
$since_date = isset($_REQUEST['since_date']) ? $_REQUEST['since_date'] : '';

// Penanganan Kondisi Query berdasarkan Action (Full vs Sync Inkrimental) menggunakan PDO
if ($action == 'sync' && !empty($since_date)) {
    $stmt = $pdo->prepare("SELECT DISTINCT stockmaster.stockid, stockmaster.categoryid, stockmaster.description, 
             stockmaster.longdescription, stockmaster.units, stockmaster.barcode, 
             stockmaster.taxcatid, stockmaster.decimalplaces,
             stockcategory.categorydescription, stockcategory.stocktype,
             COALESCE(locstock.quantity, 0) as quantity,
             COALESCE(locstock.loccode, :loccode) as loccode
             FROM stockmaster 
             INNER JOIN stockcategory ON stockmaster.categoryid = stockcategory.categoryid AND stockcategory.stocktype = 'F'
             LEFT JOIN locstock ON stockmaster.stockid = locstock.stockid AND locstock.loccode = :loccode_left
             INNER JOIN prices ON stockmaster.stockid = prices.stockid 
             WHERE prices.startdate >= :since_date AND prices.enddate = '9999-12-31'
             LIMIT :limit OFFSET :offset");
             
    $stmt->bindValue(':loccode', $loccode, PDO::PARAM_STR);
    $stmt->bindValue(':loccode_left', $loccode, PDO::PARAM_STR);
    $stmt->bindValue(':since_date', $since_date, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetchAll();

    $count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT stockmaster.stockid) as total 
                    FROM stockmaster 
                    INNER JOIN stockcategory ON stockmaster.categoryid = stockcategory.categoryid AND stockcategory.stocktype = 'F'
                    INNER JOIN prices ON stockmaster.stockid = prices.stockid 
                    WHERE prices.startdate >= :since_date AND prices.enddate = '9999-12-31'");
    $count_stmt->bindValue(':since_date', $since_date, PDO::PARAM_STR);
    $count_stmt->execute();
    $total_row = $count_stmt->fetch();
} else {
    $stmt = $pdo->prepare("SELECT stockmaster.stockid, stockmaster.categoryid, stockmaster.description, 
             stockmaster.longdescription, stockmaster.units, stockmaster.barcode, 
             stockmaster.taxcatid, stockmaster.decimalplaces,
             stockcategory.categorydescription, stockcategory.stocktype,
             COALESCE(locstock.quantity, 0) as quantity,
             COALESCE(locstock.loccode, :loccode) as loccode
             FROM stockmaster 
             INNER JOIN stockcategory ON stockmaster.categoryid = stockcategory.categoryid AND stockcategory.stocktype = 'F'
             LEFT JOIN locstock ON stockmaster.stockid = locstock.stockid AND locstock.loccode = :loccode_left
             LIMIT :limit OFFSET :offset");

    $stmt->bindValue(':loccode', $loccode, PDO::PARAM_STR);
    $stmt->bindValue(':loccode_left', $loccode, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetchAll();

    $count_stmt = $pdo->query("SELECT COUNT(DISTINCT stockmaster.stockid) as total 
                    FROM stockmaster 
                    INNER JOIN stockcategory ON stockmaster.categoryid = stockcategory.categoryid AND stockcategory.stocktype = 'F'");
    $total_row = $count_stmt->fetch();
}

$products = [];
$stockids = [];
foreach ($result as $row) {
    $row['decimalplaces'] = (int)$row['decimalplaces'];
    $row['quantity'] = (float)$row['quantity'];
    
    $products[$row['stockid']] = $row;
    $products[$row['stockid']]['prices'] = []; 
    $stockids[] = $row['stockid'];
}

// Ambil SEMUA harga aktif untuk produk pada halaman ini
if (!empty($stockids)) {
    $placeholders = implode(',', array_fill(0, count($stockids), '?'));
    $price_query = "SELECT stockid, typeabbrev, currabrev, price FROM prices WHERE stockid IN ($placeholders) AND enddate = '9999-12-31'";
    
    $price_stmt = $pdo->prepare($price_query);
    $price_stmt->execute($stockids);
    $prices_result = $price_stmt->fetchAll();

    foreach ($prices_result as $p_row) {
        $sid = $p_row['stockid'];
        if (isset($products[$sid])) {
            $products[$sid]['prices'][] = [
                'typeabbrev' => $p_row['typeabbrev'],
                'currabrev' => $p_row['currabrev'],
                'price' => (float)$p_row['price']
            ];
        }
    }
}

$products_final = array_values($products);

// Data master (hanya ditarik jika page == 1)
$currencies = [];
$debtorsmaster = [];
$custbranch = [];
$locations = [];
$salestypes = [];
$discountmatrix = [];
$holdreasons = [];
$paymentterms = [];
$paymentmethods = [];
$taxauthorities = [];
$taxauthrates = [];
$taxgroups = [];
$taxgrouptaxes = [];
$unitsofmeasure = [];
$stockcategory = [];

if ($page == 1) {
    $currencies = $pdo->query("SELECT currency, currabrev, country, hundredsname, decimalplaces, rate FROM currencies")->fetchAll();

    if ($debtorno) {
        $debtors_stmt = $pdo->prepare("SELECT debtorno, name, currcode, salestype, holdreason, paymentterms, discount, creditlimit, discountcode FROM debtorsmaster WHERE debtorno = ?");
        $debtors_stmt->execute([$debtorno]);
        $debtorsmaster = $debtors_stmt->fetchAll();
    } else {
        $debtorsmaster = $pdo->query("SELECT debtorno, name, currcode, salestype, holdreason, paymentterms, discount, creditlimit, discountcode FROM debtorsmaster LIMIT 5000")->fetchAll();
    }

    if ($debtorno && $branchcode) {
        $branch_stmt = $pdo->prepare("SELECT branchcode, debtorno, brname, contactname, specialinstructions, taxgroupid FROM custbranch WHERE debtorno = ? AND branchcode = ?");
        $branch_stmt->execute([$debtorno, $branchcode]);
        $custbranch = $branch_stmt->fetchAll();
    } else {
        $custbranch = $pdo->query("SELECT branchcode, debtorno, brname, contactname, specialinstructions, taxgroupid FROM custbranch LIMIT 5000")->fetchAll();
    }

    if ($loccode) {
        $loc_stmt = $pdo->prepare("SELECT loccode, locationname, taxprovinceid FROM locations WHERE loccode = ?");
        $loc_stmt->execute([$loccode]);
        $locations = $loc_stmt->fetchAll();
    }

    $salestypes = $pdo->query("SELECT typeabbrev, sales_type FROM salestypes")->fetchAll();
    $discountmatrix = $pdo->query("SELECT salestype, discountcategory, quantitybreak, discountrate FROM discountmatrix")->fetchAll();
    $holdreasons = $pdo->query("SELECT reasoncode, reasondescription, dissallowinvoices FROM holdreasons")->fetchAll();
    $paymentterms = $pdo->query("SELECT termsindicator, terms FROM paymentterms")->fetchAll();
    $paymentmethods = $pdo->query("SELECT paymentid, paymentname, opencashdrawer FROM paymentmethods WHERE opencashdrawer = 1")->fetchAll();
    $taxauthorities = $pdo->query("SELECT taxid, description FROM taxauthorities")->fetchAll();
    $taxauthrates = $pdo->query("SELECT taxauthority, dispatchtaxprovince, taxcatid, taxrate FROM taxauthrates")->fetchAll();
    $taxgroups = $pdo->query("SELECT taxgroupid, taxgroupdescription FROM taxgroups")->fetchAll();
    $taxgrouptaxes = $pdo->query("SELECT taxgroupid, taxauthid, calculationorder, taxontax FROM taxgrouptaxes")->fetchAll();
    $unitsofmeasure = $pdo->query("SELECT unitid, unitname FROM unitsofmeasure")->fetchAll();
    $stockcategory = $pdo->query("SELECT categoryid, categorydescription, stocktype FROM stockcategory")->fetchAll();
}

$total_data = $total_row['total'] ?? 0;

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'current_page' => $page,
    'per_page' => $limit,
    'total_records' => (int)$total_data,
    'total_pages' => ($limit > 0) ? ceil($total_data / $limit) : 1,
    'currencies' => $currencies,
    'debtorsmaster' => $debtorsmaster,
    'custbranch' => $custbranch,
    'locations' => $locations,
    'salestypes' => $salestypes,
    'discountmatrix' => $discountmatrix,
    'holdreasons' => $holdreasons,
    'paymentterms' => $paymentterms,
    'paymentmethods' => $paymentmethods,
    'taxauthorities' => $taxauthorities,
    'taxauthrates' => $taxauthrates,
    'taxgroups' => $taxgroups,
    'taxgrouptaxes' => $taxgrouptaxes,
    'unitsofmeasure' => $unitsofmeasure,
    'stockcategory' => $stockcategory,
    'data' => $products_final
]);
exit;
?>