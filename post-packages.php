<?php
/**
 * Plugin Name: نمایش روزانه مرسولات پستی
 * Plugin URI: mndev.ir
 * Description: افزونه مدیریت و نمایش لیست بسته‌های پستی با امکان آپلود Excel و نمایش جدول در فرانت‌اند
 * Version: 1.0.0
 * Author: Mojtaba Nazarzade
 * Text Domain: post-packages
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// تعریف مسیرها
define('PP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PP_PLUGIN_URL', plugin_dir_url(__FILE__));

class PostPackagesPlugin {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'post_packages';

        // هوک‌ها
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_upload_packages_excel', array($this, 'handle_excel_upload'));
        add_shortcode('post_packages_table', array($this, 'display_packages_table'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * ساخت جدول دیتابیس هنگام فعال‌سازی
     */
    public function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_name VARCHAR(255) NOT NULL,
            date DATE NOT NULL,
            city VARCHAR(100) NOT NULL,
            tracking_code VARCHAR(100) NOT NULL UNIQUE,
            shipping_type VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY date_id_index (date, id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * افزودن منو به پیشخوان
     */
    public function add_admin_menu() {
        add_menu_page(
            'بسته‌های پستی',
            'بسته‌های پستی',
            'manage_options',
            'post-packages',
            array($this, 'admin_page'),
            'dashicons-email-alt',
            30
        );
    }

    /**
     * محتوای صفحه مدیریت
     */
    public function admin_page() {
        ?>
        <div class="wrap" style="direction: rtl; text-align: right;">
            <h1>مدیریت بسته‌های پستی</h1>

            <?php
            if (isset($_GET['success']) && $_GET['success'] == '1') {
                $inserted = isset($_GET['inserted']) ? intval($_GET['inserted']) : 0;
                $skipped = isset($_GET['skipped']) ? intval($_GET['skipped']) : 0;
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo "✅ فایل با موفقیت پردازش شد. تعداد {$inserted} ردیف جدید درج شد.";
                if ($skipped > 0) {
                    echo " تعداد {$skipped} ردیف تکراری نادیده گرفته شد.";
                }
                echo '</p></div>';
            }

            if (isset($_GET['error'])) {
                $error_msg = sanitize_text_field($_GET['error']);
                echo '<div class="notice notice-error is-dismissible"><p>❌ خطا: ' . esc_html($error_msg) . '</p></div>';
            }
            ?>

            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <h2>آپلود فایل Excel</h2>
                <p>فایل Excel باید شامل ستون‌های زیر باشد: Name, Date, City, Tracking, ShippingType</p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_packages_excel">
                    <?php wp_nonce_field('upload_packages_excel_nonce', 'packages_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="excel_file">انتخاب فایل Excel</label>
                            </th>
                            <td>
                                <input type="file" name="excel_file" id="excel_file" accept=".xlsx" required>
                                <p class="description">فقط فایل‌های با فرمت .xlsx مجاز هستند</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" class="button button-primary" value="آپلود و پردازش فایل">
                    </p>
                </form>
            </div>

            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <h2>راهنما</h2>
                <ul style="list-style: disc; padding-right: 20px;">
                    <li>فایل Excel را از قسمت بالا آپلود کنید</li>
                    <li>سیستم به صورت خودکار کد رهگیری‌های تکراری را نادیده می‌گیرد</li>
                    <li>برای نمایش جدول در صفحه از شورتکد <code>[post_packages_table]</code> استفاده کنید</li>
                    <li>جدول به صورت خودکار با جدیدترین بسته‌ها در بالا نمایش داده می‌شود</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * پردازش فایل Excel آپلود شده
     */
    public function handle_excel_upload() {
        // بررسی دسترسی
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }

        // بررسی nonce
        if (!isset($_POST['packages_nonce']) || !wp_verify_nonce($_POST['packages_nonce'], 'upload_packages_excel_nonce')) {
            wp_die('درخواست نامعتبر');
        }

        // بررسی فایل آپلود شده
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $this->redirect_with_error('فایل آپلود نشد یا خطایی رخ داد');
            return;
        }

        $file = $_FILES['excel_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file_ext !== 'xlsx') {
            $this->redirect_with_error('فقط فایل‌های با فرمت .xlsx مجاز هستند');
            return;
        }

        // بارگذاری PhpSpreadsheet
        require_once PP_PLUGIN_DIR . 'vendor/autoload.php';

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // حذف سطر هدر
            array_shift($rows);

            global $wpdb;
            $inserted = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                // بررسی خالی نبودن ردیف
                if (empty($row[0]) && empty($row[3])) {
                    continue;
                }

                $customer_name = sanitize_text_field($row[0]);
                $date = $this->format_date($row[1]);
                $city = sanitize_text_field($row[2]);
                $tracking_code = sanitize_text_field($row[3]);
                $shipping_type = sanitize_text_field($row[4]);

                // بررسی تکراری نبودن کد رهگیری
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE tracking_code = %s",
                    $tracking_code
                ));

                if ($exists > 0) {
                    $skipped++;
                    continue;
                }

                // درج در دیتابیس
                $result = $wpdb->insert(
                    $this->table_name,
                    array(
                        'customer_name' => $customer_name,
                        'date' => $date,
                        'city' => $city,
                        'tracking_code' => $tracking_code,
                        'shipping_type' => $shipping_type
                    ),
                    array('%s', '%s', '%s', '%s', '%s')
                );

                if ($result) {
                    $inserted++;
                }
            }

            // حذف فایل آپلود شده
            @unlink($file['tmp_name']);

            // ریدایرکت به صفحه مدیریت با پیغام موفقیت
            wp_redirect(add_query_arg(array(
                'page' => 'post-packages',
                'success' => 1,
                'inserted' => $inserted,
                'skipped' => $skipped
            ), admin_url('admin.php')));
            exit;

        } catch (Exception $e) {
            $this->redirect_with_error('خطا در پردازش فایل: ' . $e->getMessage());
        }
    }

    /**
     * تبدیل تاریخ به فرمت مناسب
     */
    private function format_date($date_value) {
        if (is_numeric($date_value)) {
            // تاریخ Excel (serial date)
            $unix_date = ($date_value - 25569) * 86400;
            return date('Y-m-d', $unix_date);
        }

        // تلاش برای تبدیل به فرمت استاندارد
        $timestamp = strtotime($date_value);
        if ($timestamp) {
            return date('Y-m-d', $timestamp);
        }

        return date('Y-m-d');
    }

    /**
     * ریدایرکت با پیغام خطا
     */
    private function redirect_with_error($message) {
        wp_redirect(add_query_arg(array(
            'page' => 'post-packages',
            'error' => urlencode($message)
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * نمایش جدول در فرانت‌اند
     */
    public function display_packages_table($atts) {
        global $wpdb;

        // دریافت داده‌ها با ترتیب نزولی
        $packages = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY date DESC, id DESC",
            ARRAY_A
        );

        ob_start();
        ?>
        <div class="post-packages-wrapper" style="direction: rtl; text-align: right;">
            <div id="loading-spinner" style="text-align: center; padding: 20px; display: none;">
                <div class="spinner"></div>
                <p>در حال بارگذاری...</p>
            </div>

            <table id="packages-table" class="display" style="width:100%; display: none;">
                <thead>
                <tr>
                    <th>کد رهگیری</th>
                    <th>تاریخ</th>
                    <th>مقصد</th>
                    <th>نام خریدار</th>
                    <th>نوع ارسال</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($packages as $package): ?>
                    <tr>
                        <td><?php echo esc_html($package['tracking_code']); ?></td>
                        <td><?php echo esc_html($this->format_display_date($package['date'])); ?></td>
                        <td><?php echo esc_html($package['city']); ?></td>
                        <td><?php echo esc_html($package['customer_name']); ?></td>
                        <td><?php echo esc_html($package['shipping_type']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * تبدیل تاریخ به فرمت نمایشی فارسی
     */
    private function format_display_date($date) {
        $timestamp = strtotime($date);
        return date_i18n('Y/m/d', $timestamp);
    }

    /**
     * بارگذاری اسکریپت‌ها و استایل‌های فرانت‌اند
     */
    public function enqueue_frontend_assets() {
        if (!is_admin()) {
            // DataTables CSS
            wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css');

            // استایل سفارشی
            wp_enqueue_style('post-packages-style', PP_PLUGIN_URL . 'assets/style.css', array(), '1.0.0');

            // jQuery (از وردپرس)
            wp_enqueue_script('jquery');

            // DataTables JS
            wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js', array('jquery'), null, true);

            // اسکریپت سفارشی
            wp_enqueue_script('post-packages-script', PP_PLUGIN_URL . 'assets/script.js', array('jquery', 'datatables-js'), '1.0.0', true);
        }
    }

    /**
     * بارگذاری استایل‌های ادمین
     */
    public function enqueue_admin_assets($hook) {
        if ($hook == 'toplevel_page_post-packages') {
            wp_enqueue_style('post-packages-admin-style', PP_PLUGIN_URL . 'assets/admin-style.css', array(), '1.0.0');
        }
    }
}

// راه‌اندازی افزونه
new PostPackagesPlugin();