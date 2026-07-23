CREATE TABLE isekai_editor_delete_page (
  iedp_id SERIAL PRIMARY KEY,
  page_id INTEGER NOT NULL,
  page_namespace INTEGER NOT NULL,
  page_title TEXT NOT NULL,
  log_id INTEGER NULL,
  deleter_actor_id BIGINT NOT NULL,
  deleted_at TIMESTAMPTZ NOT NULL
);
CREATE UNIQUE INDEX isekai_editor_delete_page_page_log ON isekai_editor_delete_page (page_id, log_id);
CREATE INDEX isekai_editor_delete_page_title ON isekai_editor_delete_page (page_namespace, page_title);
CREATE INDEX isekai_editor_delete_page_page ON isekai_editor_delete_page (page_id);
