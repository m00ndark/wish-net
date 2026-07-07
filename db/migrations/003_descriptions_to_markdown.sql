-- 003: convert the small amount of legacy HTML in wish descriptions to markdown.
--
-- Analysis of production data: the only tag ever used is <b> (bold, 10 rows), plus the &quot;
-- entity (2 rows, from the old wash() escaping). Existing descriptions contain no markdown-active
-- characters that would reformat, so this conversion is safe.
--
-- <b>x</b> -> **x** (markdown bold); &quot; -> " (the client now renders markdown with raw HTML
-- disabled, so entities are no longer needed).

UPDATE wishes SET short_description =
    REPLACE(REPLACE(REPLACE(short_description, '<b>', '**'), '</b>', '**'), '&quot;', '"');
