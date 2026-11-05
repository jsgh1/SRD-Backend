-- Owned exclusively by the future files service. No foreign keys to another service.
CREATE TABLE file_quotas (
 organization_id CHAR(36) PRIMARY KEY,
 reserved_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE person_photos (
 organization_id CHAR(36) NOT NULL,
 person_id CHAR(36) NOT NULL,
 slot VARCHAR(16) NOT NULL,
 version BIGINT UNSIGNED NOT NULL,
 blob_id CHAR(48) NULL,
 bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 sha256 CHAR(64) NULL,
 width INT UNSIGNED NULL,
 height INT UNSIGNED NULL,
 PRIMARY KEY (organization_id,person_id,slot),
 UNIQUE KEY (blob_id),
 FOREIGN KEY (organization_id) REFERENCES file_quotas(organization_id)
) ENGINE=InnoDB;
CREATE TABLE file_garbage (
 blob_id CHAR(48) PRIMARY KEY,
 organization_id CHAR(36) NOT NULL,
 bytes BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY (organization_id) REFERENCES file_quotas(organization_id)
) ENGINE=InnoDB;
CREATE TABLE outbox_events (
 id CHAR(36) PRIMARY KEY,
 organization_id CHAR(36) NULL,
 actor_id CHAR(36) NULL,
 action VARCHAR(100) NOT NULL,
 resource_id CHAR(36) NULL,
 result VARCHAR(20) NOT NULL,
 correlation_id CHAR(36) NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 published_at DATETIME(6) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME(6) NOT NULL,
 INDEX (organization_id)
) ENGINE=InnoDB;
