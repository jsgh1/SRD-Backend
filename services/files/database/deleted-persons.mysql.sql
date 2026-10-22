CREATE TABLE file_deleted_persons (
 organization_id CHAR(36) NOT NULL,
 person_id CHAR(36) NOT NULL,
 deleted_at DATETIME(6) NOT NULL,
 PRIMARY KEY (organization_id, person_id),
 FOREIGN KEY (organization_id) REFERENCES file_quotas(organization_id)
) ENGINE=InnoDB;
