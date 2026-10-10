# CounterLogic POS v4.1

An updated desktop Point of Sales (POS) application integrated with webERP, originally based on Philip Dann's repository (`webERP-team/CounterLogicPOS`).

## 🚀 Key Features
- **Seamless webERP Integration:** Direct synchronization of sales, items, customers, and inventory data with local Indonesian webERP systems using HMAC-SHA256 secured backend API scripts (`upload_api.php` and `pos_api.php`).
- **Offline-Ready SQLite Database:** Operates smoothly on local Windows terminals using a lightweight SQLite database (`data/CounterLogic.sqlite`), ensuring uninterrupted cashier operations even during network disruptions.
- **30-Day Trial Mode:** Built-in trial period system allowing new installations to run in evaluation mode for 30 days before licensing activation.
- **Professional Branding & Support:** Custom About window displaying clear technical support information (`support@it-pro.co.id` and `www.it-pro.co.id`) with distinct text styling.
- **Robust Windows Installer:** Packaged via PyInstaller and Inno Setup (`counterlogic.iss`) with full access permissions (`users-full`) configured for safe installation in Windows directories.

## 📁 Repository Structure
- `installer/`: Contains the compiled Windows installer (`CounterLogic_Setup_v.4.1.exe`).
- `api/`: Contains the backend PHP synchronization scripts (`upload_api.php`, `pos_api.php`).

## 🛠️ Installation & Setup
1. Download and run `CounterLogic_Setup_v.4.1.exe` from the Releases page.
2. Place the backend API scripts from the `api/` folder onto your webERP server root directory.
3. Configure your local database and start processing transactions!

## Credits
Forked and customized from [webERP-team/CounterLogicPOS](https://github.com/webERP-team/CounterLogicPOS) by Phil Daintree.
