jQuery(document).ready(function($) {
    // نمایش لودینگ
    $('#loading-spinner').show();
    
    // تنظیمات DataTables با زبان فارسی
    $('#packages-table').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/fa.json",
            "search": "جستجو:",
            "lengthMenu": "نمایش _MENU_ ردیف در هر صفحه",
            "info": "نمایش _START_ تا _END_ از _TOTAL_ ردیف",
            "infoEmpty": "هیچ رکوردی یافت نشد",
            "infoFiltered": "(فیلتر شده از _MAX_ ردیف)",
            "zeroRecords": "داده‌ای یافت نشد",
            "paginate": {
                "first": "اول",
                "last": "آخر",
                "next": "بعدی",
                "previous": "قبلی"
            }
        },
        "pageLength": 20,
        "order": [[1, 'desc']], // مرتب‌سازی بر اساس تاریخ (ستون دوم) نزولی
        "responsive": true,
        "dom": 'frtip',
        "initComplete": function() {
            // مخفی کردن لودینگ و نمایش جدول
            $('#loading-spinner').hide();
            $('#packages-table').show();
        }
    });
    
    // تنظیم placeholder برای جستجو
    $('.dataTables_filter input').attr('placeholder', 'جستجو در کد رهگیری، نام یا شهر...');
});