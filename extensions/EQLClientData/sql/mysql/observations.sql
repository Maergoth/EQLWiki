-- Private data-only ingestion. No page/revision, chat, screenshot or player fields.
CREATE TABLE /*_*/eql_eoz_state (
  eos_id INT UNSIGNED NOT NULL,
  eos_events BIGINT UNSIGNED NOT NULL DEFAULT 0,
  eos_batches BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (eos_id)
) ENGINE=InnoDB DEFAULT CHARSET=binary;

INSERT INTO /*_*/eql_eoz_state (eos_id, eos_events, eos_batches) VALUES (1, 0, 0);

CREATE TABLE /*_*/eql_eoz_batches (
  eob_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  eob_actor BIGINT UNSIGNED NOT NULL,
  eob_key VARBINARY(120) NOT NULL,
  eob_hash BINARY(64) NOT NULL,
  eob_received BINARY(14) NOT NULL,
  eob_client VARBINARY(160) NOT NULL,
  eob_receipt BLOB NOT NULL,
  PRIMARY KEY (eob_id),
  UNIQUE KEY eob_actor_key (eob_actor, eob_key),
  KEY eob_actor_received (eob_actor, eob_received)
) ENGINE=InnoDB DEFAULT CHARSET=binary;

CREATE TABLE /*_*/eql_eoz_observations (
  eoo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  eoo_actor BIGINT UNSIGNED NOT NULL,
  eoo_key VARBINARY(120) NOT NULL,
  eoo_hash BINARY(64) NOT NULL,
  eoo_batch VARBINARY(120) NOT NULL,
  eoo_kind VARBINARY(8) NOT NULL,
  eoo_observed BINARY(14) NOT NULL,
  eoo_received BINARY(14) NOT NULL,
  eoo_payload BLOB NOT NULL,
  PRIMARY KEY (eoo_id),
  UNIQUE KEY eoo_actor_key (eoo_actor, eoo_key),
  KEY eoo_actor_received (eoo_actor, eoo_received),
  KEY eoo_kind_observed (eoo_kind, eoo_observed)
) ENGINE=InnoDB DEFAULT CHARSET=binary;
