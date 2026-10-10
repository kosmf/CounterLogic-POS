<?php
//upload_api.php
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$nama_file_koneksi = 'config.php'; 
$db_config_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $nama_file_koneksi;

if (file_exists($db_config_path)) {
    require_once $db_config_path;
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'File koneksi database di root tidak ditemukan.']);
    exit;
}

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

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
if ($action !== 'upload_transactions') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

$transactions_json = isset($_POST['transactions']) ? $_POST['transactions'] : '[]';
$transactions = json_decode($transactions_json, true);

if (empty($transactions)) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'No transactions provided.']);
    exit;
}

// Fungsi Ambil Nomor Transaksi Mengacu Sepenuhnya ke systypes dengan Kunci Transaksi
function getNextTransNo($pdo, $type) {
    $stmtSys = $pdo->prepare("SELECT typeno FROM systypes WHERE typeid = ? FOR UPDATE");
    $stmtSys->execute([$type]);
    $row = $stmtSys->fetch();
    
    if (!$row) {
        throw new Exception("Type ID $type tidak ditemukan di tabel systypes.");
    }
    
    $nextNo = (int)$row['typeno'];

    $update = $pdo->prepare("UPDATE systypes SET typeno = ? WHERE typeid = ?");
    $update->execute([$nextNo + 1, $type]);
    
    return $nextNo;
}

// Fungsi Penentuan Periode Sesuai Logika Resmi webERP
function getPeriodFromTransactionDate($pdo, $trandate) {
    $transTimestamp = strtotime($trandate);
    $monthAfterTransDate = date('Y-m-d', mktime(0, 0, 0, date('m', $transTimestamp) + 1, date('d', $transTimestamp), date('Y', $transTimestamp)));
    
    $stmt = $pdo->prepare("SELECT periodno 
                           FROM periods 
                           WHERE lastdate_in_period < ? 
                           AND lastdate_in_period >= ? 
                           LIMIT 1");
    $stmt->execute([$monthAfterTransDate, $trandate]);
    $periodno = $stmt->fetchColumn();
    
    return $periodno ? $periodno : 1;
}

// Fungsi Pengecekan Keseimbangan GL ala webERP
function EnsureGLEntriesBalance($pdo, $TransType, $TransTypeNo) {
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM gltrans WHERE type = ? AND typeno = ?");
    $stmt->execute([$TransType, $TransTypeNo]);
    $difference = (float)$stmt->fetchColumn();

    if (abs($difference) != 0) {
        if (abs($difference) > 0.1) {
            throw new Exception("The general ledger entries created for Type $TransType No $TransTypeNo do not balance (Difference: $difference).");
        } else {
            $stmtMax = $pdo->prepare("SELECT counterindex FROM gltrans WHERE type = ? AND typeno = ? ORDER BY counterindex DESC LIMIT 1");
            $stmtMax->execute([$TransType, $TransTypeNo]);
            $transToAmend = $stmtMax->fetchColumn();

            if ($transToAmend) {
                $stmtUpd = $pdo->prepare("UPDATE gltrans SET amount = amount - ? WHERE counterindex = ?");
                $stmtUpd->execute([$difference, $transToAmend]);
            }
        }
    }
}

$success_count = 0;
$processed_refs = [];

try {
    foreach ($transactions as $tx) {
        $pdo->beginTransaction();

        $trandate = $tx['trandate'];
        $transno_lokal = $tx['transno']; 
        $debtorno = $tx['debtorno'];
        $branchcode = $tx['branchcode'];
        $tpe = $tx['tpe'];
        $ovamount = $tx['ovamount'];
        $ovgst = $tx['ovgst'] ?? 0;
        $total_invoice = $ovamount + $ovgst;
        
        // Membentuk referensi otomatis dengan format POS-[transno_lokal]
        $reference = "POS-" . $transno_lokal;
        
        $paymentmethod = $tx['paymentmethod'] ?? 'Cash';
        $fromstkloc = $tx['fromstkloc'] ?? 'PST';
        $user_kasir = $tx['user'] ?? 'admin';
        
        $items = $tx['items'] ?? [];

        $inputBankAccount = $tx['bankaccount'] ?? '1010';
        $checkAccountStmt = $pdo->prepare("SELECT accountcode FROM chartmaster WHERE accountcode = ?");
        $checkAccountStmt->execute([$inputBankAccount]);
        $validAccount = $checkAccountStmt->fetchColumn();

        if (!$validAccount) {
            $defaultAccStmt = $pdo->query("SELECT accountcode FROM chartmaster LIMIT 1");
            $validAccount = $defaultAccStmt->fetchColumn() ?: '1010';
        }
        $bankAccount = $validAccount;

        $invoiceNo = getNextTransNo($pdo, 10);
        $receiptNo = getNextTransNo($pdo, 12);
        $orderNo   = getNextTransNo($pdo, 30);
        $periodNo  = getPeriodFromTransactionDate($pdo, $trandate);

        $coyStmt = $pdo->query("SELECT debtorsact, gllink_debtors, gllink_stock FROM companies WHERE coycode = 1");
        $coyRow = $coyStmt->fetch();
        $debtorsAct = $coyRow['debtorsact'] ?? '10909001';
        $glLinkDebtors = $coyRow['gllink_debtors'] ?? 1;

        $custNameStmt = $pdo->prepare("SELECT name FROM debtorsmaster WHERE debtorno = ?");
        $custNameStmt->execute([$debtorno]);
        $deliverToName = $custNameStmt->fetchColumn();
        $deliverTo = $deliverToName ? $deliverToName : $debtorno;

        // 1. Insert Sales Orders
        $ordHeaderStmt = $pdo->prepare("INSERT INTO salesorders (orderno, debtorno, branchcode, customerref, orddate, deliverydate, ordertype, fromstkloc, deliverto, shipvia, user) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
        $ordHeaderStmt->execute([
            $orderNo, $debtorno, $branchcode, $reference, $trandate, $trandate, $tpe, $fromstkloc, $deliverTo, $user_kasir
        ]);

        $oline = 0;
        foreach ($items as $item) {
            $stockid = $item['stockid'];
            $price = $item['price'];
            $qty = $item['qty'];
            $discount = isset($item['discountpercent']) ? $item['discountpercent'] : 0;

            $ordDetStmt = $pdo->prepare("INSERT INTO salesorderdetails (orderno, stkcode, unitprice, quantity, discountpercent, itemdue, completed, orderlineno) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
            $ordDetStmt->bindValue(1, $orderNo, PDO::PARAM_INT);
            $ordDetStmt->bindValue(2, $stockid, PDO::PARAM_STR);
            $ordDetStmt->bindValue(3, $price, PDO::PARAM_STR);
            $ordDetStmt->bindValue(4, $qty, PDO::PARAM_STR);
            $ordDetStmt->bindValue(5, $discount, PDO::PARAM_STR);
            $ordDetStmt->bindValue(6, $trandate, PDO::PARAM_STR);
            $ordDetStmt->bindValue(7, $oline, PDO::PARAM_INT);
            $ordDetStmt->execute();

            $costStmt = $pdo->prepare("SELECT materialcost + labourcost + overheadcost AS standardcost FROM stockmaster WHERE stockid = ?");
            $costStmt->execute([$stockid]);
            $costRow = $costStmt->fetch();
            $standardCost = $costRow ? $costRow['standardcost'] : 0;

            $moveStmt = $pdo->prepare("INSERT INTO stockmoves (stockid, type, transno, loccode, trandate, debtorno, branchcode, price, prd, reference, qty, discountpercent, standardcost, userid) 
                                       VALUES (?, 10, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $moveStmt->execute([$stockid, $invoiceNo, $fromstkloc, $trandate, $debtorno, $branchcode, $price, $periodNo, $reference, $qty, $discount, $standardCost, $user_kasir]);

            $locStmt = $pdo->prepare("UPDATE locstock SET quantity = quantity - ? WHERE stockid = ? AND loccode = ?");
            $locStmt->execute([$qty, $stockid, $fromstkloc]);

            $oline++;
        }

        // 2. Insert DebtorTrans (Type 10)
        $stmt = $pdo->prepare("INSERT INTO debtortrans (transno, type, debtorno, branchcode, trandate, inputdate, prd, reference, tpe, ovamount, ovgst, rate, settled, order_, user) 
                               VALUES (?, 10, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, 1, 0, ?, ?)");
        $stmt->execute([
            $invoiceNo, 
            $debtorno, 
            $branchcode, 
            $trandate, 
            $periodNo, 
            $reference, 
            $tpe, 
            $ovamount, 
            $ovgst, 
            $orderNo,
            $user_kasir
        ]);
        
        $InvoiceId = $pdo->lastInsertId();

        // 4. GL Trans & Taxes (Type 10)
        if ($glLinkDebtors == 1) {
            $glInvDebet = $pdo->prepare("INSERT INTO gltrans (type, typeno, trandate, periodno, account, narrative, amount, user) VALUES (10, ?, ?, ?, ?, ?, ?, ?)");
            $glInvDebet->execute([$invoiceNo, $trandate, $periodNo, $debtorsAct, "Invoice - $debtorno - Ref: $reference", -$total_invoice, $user_kasir]);

            foreach ($items as $item) {
                $stockid = $item['stockid'];
                $price = $item['price'];
                $qty = $item['qty'];
                
                $discountPercent = isset($item['discountpercent']) ? (float)$item['discountpercent'] : 0.0;
                
                $grossLineTotal = $price * $qty;
                $discountAmount = $grossLineTotal * ($discountPercent / 100.0);

                $glAccStmt = $pdo->prepare("SELECT salesglpostings.salesglcode, salesglpostings.discountglcode 
                                           FROM salesglpostings 
                                           INNER JOIN stockmaster ON salesglpostings.stkcat = stockmaster.categoryid OR salesglpostings.stkcat = 'ANY'
                                           INNER JOIN custbranch ON salesglpostings.area = custbranch.area OR salesglpostings.area = 'ANY'
                                           WHERE stockmaster.stockid = ? 
                                           AND custbranch.debtorno = ? 
                                           AND custbranch.branchcode = ?
                                           AND salesglpostings.salestype = ?
                                           LIMIT 1");
                $glAccStmt->execute([$stockid, $debtorno, $branchcode, $tpe]);
                $glPosting = $glAccStmt->fetch(PDO::FETCH_ASSOC);

                $salesGlCode = $glPosting['salesglcode'] ?? '15555001';
                $discountGlCode = $glPosting['discountglcode'] ?? '15656001';

                $glInvKredit = $pdo->prepare("INSERT INTO gltrans (type, typeno, trandate, periodno, account, narrative, amount, user) VALUES (10, ?, ?, ?, ?, ?, ?, ?)");
                $glInvKredit->execute([$invoiceNo, $trandate, $periodNo, $salesGlCode, "Sales Item $stockid - Ref: $reference", -$grossLineTotal, $user_kasir]);

                if ($discountPercent != 0) {
                    $glDisc = $pdo->prepare("INSERT INTO gltrans (type, typeno, trandate, periodno, account, narrative, amount, user) VALUES (10, ?, ?, ?, ?, ?, ?, ?)");
                    $glDisc->execute([$invoiceNo, $trandate, $periodNo, $discountGlCode, "Discount Item $stockid - Ref: $reference", $discountAmount, $user_kasir]);
                }
            }

            if ($ovgst > 0) {
                $taxAuthStmt = $pdo->prepare("SELECT taxauthid FROM taxgrouptaxes INNER JOIN custbranch ON taxgrouptaxes.taxgroupid = custbranch.taxgroupid WHERE custbranch.debtorno = ? AND custbranch.branchcode = ? LIMIT 1");
                $taxAuthStmt->execute([$debtorno, $branchcode]);
                $taxAuthRow = $taxAuthStmt->fetch();
                $taxAuthId = $taxAuthRow['taxauthid'] ?? 1;

                $taxGlStmt = $pdo->prepare("SELECT taxglcode FROM taxauthorities WHERE taxid = ?");
                $taxGlStmt->execute([$taxAuthId]);
                $taxGlRow = $taxGlStmt->fetch();
                $taxGlCode = $taxGlRow['taxglcode'] ?? '17878003';

                $taxTransStmt = $pdo->prepare("INSERT INTO debtortranstaxes (debtortransid, taxauthid, taxamount) VALUES (?, ?, ?)");
                $taxTransStmt->execute([$InvoiceId, $taxAuthId, $ovgst]);

                $glTax = $pdo->prepare("INSERT INTO gltrans (type, typeno, trandate, periodno, account, narrative, amount, user) VALUES (10, ?, ?, ?, ?, ?, ?, ?)");
                $glTax->execute([$invoiceNo, $trandate, $periodNo, $taxGlCode, "Tax on Invoice - Ref: $reference", -$ovgst, $user_kasir]);
            }

            EnsureGLEntriesBalance($pdo, 10, $invoiceNo);
        }

        // 5. Bank Trans & Receipt (Type 12)
        $bankStmt = $pdo->prepare("INSERT INTO banktrans (type, transno, bankact, ref, exrate, functionalexrate, transdate, banktranstype, amount, currcode, user) 
                                   VALUES (12, ?, ?, ?, 1, 1, ?, ?, ?, 'IDR', ?)");
        $bankStmt->execute([
            $receiptNo, 
            $bankAccount, 
            $reference, 
            $trandate, 
            $paymentmethod, 
            $total_invoice,
            $user_kasir
        ]);

        $gl1 = $pdo->prepare("INSERT INTO gltrans (type, typeno, trandate, periodno, account, narrative, amount, user) VALUES (12, ?, ?, ?, ?, ?, ?, ?)");
        $gl1->execute([$receiptNo, $trandate, $periodNo, $debtorsAct, "POS Receipt - Ref: $reference", -$total_invoice, $user_kasir]);

        $gl2 = $pdo->prepare("INSERT INTO gltrans (type, typeno, trandate, periodno, account, narrative, amount, user) VALUES (12, ?, ?, ?, ?, ?, ?, ?)");
        $gl2->execute([$receiptNo, $trandate, $periodNo, $bankAccount, "POS Deposit - Ref: $reference", $total_invoice, $user_kasir]);

        EnsureGLEntriesBalance($pdo, 12, $receiptNo);

        $dt12 = $pdo->prepare("INSERT INTO debtortrans (transno, type, debtorno, branchcode, trandate, inputdate, prd, reference, rate, ovamount, settled, user) 
                               VALUES (?, 12, ?, ?, ?, NOW(), ?, ?, 1, ?, 0, ?)");
        $dt12->execute([$receiptNo, $debtorno, $branchcode, $trandate, $periodNo, $reference, -$total_invoice, $user_kasir]);

        $ReceiptId = $pdo->lastInsertId();

        // 6. Cust Allocations
        if ($total_invoice > 0) {
            $allocStmt = $pdo->prepare("INSERT INTO custallocns (datealloc, amt, transid_allocfrom, transid_allocto) VALUES (NOW(), ?, ?, ?)");
            $allocStmt->execute([$total_invoice, $ReceiptId, $InvoiceId]);

            $updInv = $pdo->prepare("UPDATE debtortrans SET alloc = alloc + ?, settled = 1 WHERE id = ?");
            $updInv->execute([$total_invoice, $InvoiceId]);

            $updRec = $pdo->prepare("UPDATE debtortrans SET alloc = alloc - ?, settled = 1 WHERE id = ?");
            $updRec->execute([$total_invoice, $ReceiptId]);
        }

        // 7. Rekam Audit Trail sesuai kaidah webERP
        $auditText = "Insert Invoice No: $invoiceNo | Receipt No: $receiptNo | Ref: $reference | Customer: $debtorno";
        $auditStmt = $pdo->prepare("INSERT INTO audittrail (transactiondate, userid, querystring) VALUES (NOW(), ?, ?)");
        $auditStmt->execute([$user_kasir, $auditText]);

        $pdo->commit();
        $success_count++;
        $processed_refs[] = $reference;
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'success',
        'message' => "Successfully uploaded $success_count transactions.",
        'processed_references' => $processed_refs
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Upload failed at Line ' . $e->getLine() . ': ' . $e->getMessage()
    ]);
}
exit;
?>