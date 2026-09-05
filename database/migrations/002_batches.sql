CREATE INDEX IF NOT EXISTS idx_movements_batch
    ON stock_movements(batch_id);

CREATE INDEX IF NOT EXISTS idx_batches_article
    ON batches(article_id);

CREATE INDEX IF NOT EXISTS idx_batches_expiry
    ON batches(expiry_date);
