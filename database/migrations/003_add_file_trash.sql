ALTER TABLE files
    ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
    ADD KEY files_user_deleted_index (user_id, deleted_at);
