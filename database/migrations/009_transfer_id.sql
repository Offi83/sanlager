/*
 * Umbuchungen bestehen aus zwei Bewegungen (Abgang am Quell-, Zugang am
 * Ziel-Lagerort; bei einer Rücknahme transfer_reversal_out/_in). Bisher
 * wurden sie nur darüber einander zugeordnet, dass der Zugang direkt nach
 * dem Abgang gespeichert wurde (ID + 1). transfer_id verbindet beide
 * Hälften jetzt ausdrücklich: gleiche transfer_id = eine Umbuchung.
 */
ALTER TABLE stock_movements ADD COLUMN transfer_id INTEGER;

CREATE INDEX IF NOT EXISTS idx_movements_transfer
    ON stock_movements(transfer_id);

/*
 * Bestehende Umbuchungen übernehmen: Paare nach der bisherigen Regel
 * (Zugang = ID des Abgangs + 1, gleicher Artikel/Charge, Gegenmenge)
 * bekommen als transfer_id die ID ihres Abgangs.
 */
UPDATE stock_movements
SET transfer_id = id
WHERE movement_type IN ('transfer_out', 'transfer_reversal_out')
AND EXISTS (
    SELECT 1
    FROM stock_movements i
    WHERE i.id = stock_movements.id + 1
    AND i.article_id = stock_movements.article_id
    AND i.batch_id IS stock_movements.batch_id
    AND i.quantity = -stock_movements.quantity
    AND i.movement_type = CASE stock_movements.movement_type
        WHEN 'transfer_out' THEN 'transfer_in'
        ELSE 'transfer_reversal_in'
    END
);

UPDATE stock_movements
SET transfer_id = id - 1
WHERE movement_type IN ('transfer_in', 'transfer_reversal_in')
AND EXISTS (
    SELECT 1
    FROM stock_movements o
    WHERE o.id = stock_movements.id - 1
    AND o.transfer_id = o.id
);
