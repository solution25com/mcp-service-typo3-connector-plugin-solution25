CREATE TABLE tx_aiconnector_connection (
    uid int(11) unsigned NOT NULL auto_increment,
    site_identifier varchar(255) DEFAULT '' NOT NULL,
    site_url varchar(2048) DEFAULT '' NOT NULL,
    gateway_url varchar(2048) DEFAULT '' NOT NULL,
    gateway_connection_id varchar(255) DEFAULT '' NOT NULL,
    connection_secret varchar(255) DEFAULT '' NOT NULL,
    client_name varchar(255) DEFAULT '' NOT NULL,
    access_level varchar(255) DEFAULT '' NOT NULL,
    permissions text,
    requested_permissions text,
    token_hash varchar(64) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    be_group int(11) unsigned DEFAULT '0' NOT NULL,
    connected_at int(11) unsigned DEFAULT '0' NOT NULL,
    connected_by int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY site (site_identifier),
    KEY token (token_hash)
);

CREATE TABLE tx_aiconnector_audit (
    uid int(11) unsigned NOT NULL auto_increment,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    site_identifier varchar(255) DEFAULT '' NOT NULL,
    action varchar(32) DEFAULT '' NOT NULL,
    outcome varchar(32) DEFAULT '' NOT NULL,
    reason varchar(64) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    details text,

    PRIMARY KEY (uid),
    KEY site (site_identifier, crdate)
);
