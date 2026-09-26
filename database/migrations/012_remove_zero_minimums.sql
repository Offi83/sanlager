/*
 * Ein Mindestbestand von 0 kann nie unterschritten werden – er war nur
 * eine Überwachung, die nie anschlägt. Seit „0 = nicht überwacht“ gilt
 * (ArticleActions::setMinimums()), werden solche Einträge entfernt.
 */
DELETE FROM article_location_minimums WHERE minimum_stock <= 0;
