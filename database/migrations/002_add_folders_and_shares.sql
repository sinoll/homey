CREATE TABLE folders (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    parent_id CHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY folders_user_parent_index (user_id, parent_id),
    CONSTRAINT folders_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT folders_parent_fk FOREIGN KEY (parent_id) REFERENCES folders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE files
    ADD COLUMN folder_id CHAR(36) NULL AFTER user_id,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at,
    ADD KEY files_user_folder_created_index (user_id, folder_id, created_at),
    ADD CONSTRAINT files_folder_fk FOREIGN KEY (folder_id) REFERENCES folders (id) ON DELETE CASCADE;

CREATE TABLE shares (
    id CHAR(36) NOT NULL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    user_id CHAR(36) NOT NULL,
    file_id CHAR(36) NULL,
    folder_id CHAR(36) NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY shares_token_hash_unique (token_hash),
    KEY shares_user_created_index (user_id, created_at),
    KEY shares_file_index (file_id),
    KEY shares_folder_index (folder_id),
    CONSTRAINT shares_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT shares_file_fk FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE CASCADE,
    CONSTRAINT shares_folder_fk FOREIGN KEY (folder_id) REFERENCES folders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
