INSERT IGNORE INTO `settings` (`created_at`, `created_by`, `active`, `skey`, `svalue`) VALUES
    (NOW(), 0, 'yes', 'sales_override',        ''),
    (NOW(), 0, 'yes', 'sales_window_start_at', ''),
    (NOW(), 0, 'yes', 'sales_window_end_at',   '');
