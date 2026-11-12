-- The reference application's one demo owner (account 1); idempotent.
INSERT INTO `Accounts` (`id`, `email`, `password`) VALUES (1, 'demo-owner@example.invalid', NULL)
    ON DUPLICATE KEY UPDATE `email` = VALUES(`email`);
