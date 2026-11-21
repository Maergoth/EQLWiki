-- Optional, rebuildable projections. Never alter the private ingestion/receipt tables.
CREATE TABLE /*_*/eql_drop_state (
  dps_id INT UNSIGNED NOT NULL,
  dps_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dps_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dps_ready TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (dps_id)
) ENGINE=InnoDB DEFAULT CHARSET=binary;
INSERT INTO /*_*/eql_drop_state (dps_id,dps_revision,dps_cursor,dps_ready) VALUES (1,0,0,0);

CREATE TABLE /*_*/eql_drop_scopes (
  dsc_id BINARY(64) NOT NULL,
  dsc_reports BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (dsc_id)
) ENGINE=InnoDB DEFAULT CHARSET=binary;

CREATE TABLE /*_*/eql_drop_entries (
  den_id BINARY(64) NOT NULL,
  den_scope BINARY(64) NOT NULL,
  den_zone VARBINARY(960) NOT NULL,
  den_npc VARBINARY(960) NOT NULL,
  den_item VARBINARY(960) NOT NULL,
  den_zone_title VARBINARY(960) NULL,
  den_npc_title VARBINARY(960) NULL,
  den_item_title VARBINARY(960) NULL,
  den_instance VARBINARY(8) NOT NULL,
  den_search VARBINARY(3200) NOT NULL,
  den_reports BIGINT UNSIGNED NOT NULL,
  den_quantity BIGINT UNSIGNED NOT NULL,
  den_contributors BIGINT UNSIGNED NOT NULL,
  den_revision BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (den_id),
  KEY den_scope (den_scope),
  KEY den_order (den_zone,den_npc,den_instance,den_item,den_id),
  KEY den_item (den_item,den_id),
  KEY den_npc (den_npc,den_id)
) ENGINE=InnoDB DEFAULT CHARSET=binary;

CREATE TABLE /*_*/eql_drop_records (
  dpr_observation BIGINT UNSIGNED NOT NULL,
  dpr_entry BINARY(64) NULL,
  dpr_sequence BIGINT UNSIGNED NOT NULL,
  dpr_observed VARBINARY(24) NULL,
  dpr_received BINARY(20) NULL,
  dpr_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  dpr_character VARBINARY(480) NULL,
  PRIMARY KEY (dpr_observation),
  KEY dpr_entry_order (dpr_entry,dpr_observation),
  KEY dpr_entry_sequence (dpr_entry,dpr_sequence)
) ENGINE=InnoDB DEFAULT CHARSET=binary;

CREATE TABLE /*_*/eql_drop_contributors (
  dco_entry BINARY(64) NOT NULL,
  dco_actor BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (dco_entry,dco_actor)
) ENGINE=InnoDB DEFAULT CHARSET=binary;
