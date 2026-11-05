CREATE TABLE asset_photos (
 organization_id CHAR(36) NOT NULL,
 asset_id CHAR(36) NOT NULL,
 slot VARCHAR(16) NOT NULL,
 version BIGINT UNSIGNED NOT NULL,
 blob_id CHAR(48) NULL,
 bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 sha256 CHAR(64) NULL,
 width INT UNSIGNED NULL,
 height INT UNSIGNED NULL,
 PRIMARY KEY (organization_id,asset_id,slot),
 UNIQUE KEY (blob_id),
 FOREIGN KEY (organization_id) REFERENCES file_quotas(organization_id)
) ENGINE=InnoDB;
