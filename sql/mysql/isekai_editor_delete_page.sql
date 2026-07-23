CREATE TABLE /*_*/isekai_editor_delete_page (
  `iedp_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `page_id` INT UNSIGNED NOT NULL,
  `page_namespace` INT NOT NULL,
  `page_title` VARBINARY(255) NOT NULL,
  `log_id` INT UNSIGNED NULL,
  `deleter_actor_id` BIGINT UNSIGNED NOT NULL,
  `deleted_at` BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;
ALTER TABLE /*_*/isekai_editor_delete_page ADD UNIQUE KEY `iedp_page_log` (`page_id`, `log_id`);
ALTER TABLE /*_*/isekai_editor_delete_page ADD INDEX `iedp_title` (`page_namespace`, `page_title`);
ALTER TABLE /*_*/isekai_editor_delete_page ADD INDEX `iedp_page` (`page_id`);
