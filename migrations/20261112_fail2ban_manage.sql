-- Фаза 2 fail2ban: действия из панели.
-- Пометка «разбан запрошен» на активном бане: UI показывает ожидание,
-- пока агент не применит unban и следующий снимок не уберёт бан из active.

ALTER TABLE fail2ban_bans
    ADD COLUMN unban_requested_at DATETIME NULL AFTER last_seen,
    ADD COLUMN unban_requested_by INT NULL AFTER unban_requested_at;
