-- SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
-- SPDX-License-Identifier: MIT
--
-- Generated from koh/poppy's PoppySchema with the prefix tx_kohpoppy_ (MySQL
-- platform), so the TYPO3 tables are the same as the Sylius ones. Regenerate it
-- rather than editing it when the schema changes.

CREATE TABLE tx_kohpoppy_session (
    id VARCHAR(64) NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    account_id VARCHAR(191) DEFAULT NULL,
    account_token_id VARCHAR(64) DEFAULT NULL,
    scopes VARCHAR(1024) DEFAULT '' NOT NULL,
    created_at INT NOT NULL,
    expires_at INT NOT NULL,
    KEY tx_kohpoppy_session_account_token (account_token_id),
    KEY tx_kohpoppy_session_expires (expires_at),
    PRIMARY KEY (id)
);

CREATE TABLE tx_kohpoppy_account_token (
    id VARCHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    account_id VARCHAR(191) NOT NULL,
    scopes VARCHAR(1024) DEFAULT '' NOT NULL,
    issued_at INT NOT NULL,
    expires_at INT DEFAULT NULL,
    revoked_at INT DEFAULT NULL,
    UNIQUE KEY tx_kohpoppy_account_token_hash (hash),
    KEY tx_kohpoppy_account_token_account (account_id),
    PRIMARY KEY (id)
);

CREATE TABLE tx_kohpoppy_session_token (
    hash CHAR(64) NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    type VARCHAR(8) NOT NULL,
    jkt VARCHAR(64) DEFAULT NULL,
    resource VARCHAR(512) DEFAULT NULL,
    scopes VARCHAR(1024) DEFAULT '' NOT NULL,
    account_token_id VARCHAR(64) DEFAULT NULL,
    issued_at INT NOT NULL,
    expires_at INT NOT NULL,
    KEY tx_kohpoppy_session_token_expires (expires_at),
    PRIMARY KEY (hash)
);

CREATE TABLE tx_kohpoppy_authorization_code (
    hash CHAR(64) NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    redirect_uri VARCHAR(2048) NOT NULL,
    code_challenge VARCHAR(64) NOT NULL,
    account_id VARCHAR(191) NOT NULL,
    scopes VARCHAR(1024) DEFAULT '' NOT NULL,
    expires_at INT NOT NULL,
    PRIMARY KEY (hash)
);

CREATE TABLE tx_kohpoppy_device_authorization (
    device_code_hash CHAR(64) NOT NULL,
    user_code VARCHAR(9) NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    requested_scopes VARCHAR(1024) DEFAULT '' NOT NULL,
    granted_scopes VARCHAR(1024) DEFAULT '' NOT NULL,
    status VARCHAR(16) NOT NULL,
    account_id VARCHAR(191) DEFAULT NULL,
    expires_at INT NOT NULL,
    interval_seconds INT NOT NULL,
    last_polled_at INT DEFAULT NULL,
    KEY tx_kohpoppy_device_user_code (user_code),
    PRIMARY KEY (device_code_hash)
);

CREATE TABLE tx_kohpoppy_replay (
    id_hash CHAR(64) NOT NULL,
    expires_at INT NOT NULL,
    KEY tx_kohpoppy_replay_expires (expires_at),
    PRIMARY KEY (id_hash)
);

CREATE TABLE tx_kohpoppy_feedback (
    id INT AUTO_INCREMENT NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    signed_in TINYINT DEFAULT 0 NOT NULL,
    kind VARCHAR(64) NOT NULL,
    task VARCHAR(255) NOT NULL,
    description LONGTEXT NOT NULL,
    received_at INT NOT NULL,
    KEY tx_kohpoppy_feedback_received (received_at),
    PRIMARY KEY (id)
);
