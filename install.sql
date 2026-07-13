-- SVJ Od Vysoké – databázová struktura
-- Spusťte v phpMyAdmin nebo MySQL klientu
-- Vygenerováno ze skutečného produkčního/testovacího schématu (svj_test), 2026-07-13

SET NAMES utf8mb4;
SET time_zone = '+01:00';
SET FOREIGN_KEY_CHECKS = 0;

-- Uživatelé (výbor = admin/superadmin, vlastníci = owner, nájemníci = tenant)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','admin','owner','tenant') NOT NULL DEFAULT 'owner',
  unit_id INT NULL,
  tenant_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jednotky (byty, garáže, sklepy)
CREATE TABLE IF NOT EXISTS units (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(60) NOT NULL,
  type ENUM('byt','garáž','sklep','jiné') DEFAULT 'byt',
  floor TINYINT NULL,
  area_m2 DECIMAL(6,2) NULL,
  share_numerator INT NULL COMMENT 'podíl – čitatel',
  share_denominator INT NULL COMMENT 'podíl – jmenovatel',
  linked_unit_id INT NULL COMMENT 'ID bytu ke kterému garáž patří',
  np TINYINT NULL COMMENT 'podlaží (NP)',
  dispozice VARCHAR(20) NULL COMMENT 'např. 1+kk',
  vymera_m2 DECIMAL(6,2) NULL COMMENT 'přesná výměra dle technického popisu',
  vymera_pozn VARCHAR(200) NULL COMMENT 'poznámka k výměře',
  FOREIGN KEY (linked_unit_id) REFERENCES units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Místnosti a vybavení jednotky (technický popis)
CREATE TABLE IF NOT EXISTS unit_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  nazev VARCHAR(120) NOT NULL,
  vymera_m2 DECIMAL(6,2) NULL,
  poznamka VARCHAR(200) NULL,
  order_num INT DEFAULT 0,
  FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS unit_equipment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  polozka VARCHAR(120) NOT NULL,
  pocet INT NOT NULL DEFAULT 1,
  poznamka VARCHAR(200) NULL,
  order_num INT DEFAULT 0,
  FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kartotéka vlastníků
CREATE TABLE IF NOT EXISTS owners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(120),
  email2 VARCHAR(120),
  primary_email TINYINT(1) DEFAULT 1 COMMENT '1=email je hlavní, 2=email2 je hlavní',
  phone VARCHAR(30),
  phone2 VARCHAR(30),
  primary_phone TINYINT(1) DEFAULT 1 COMMENT '1=phone je hlavní, 2=phone2 je hlavní',
  address VARCHAR(200) COMMENT 'korespondenční adresa, pokud se liší',
  residence ENUM('trvalé','pronájem','druhé bydliště','neuvedeno') DEFAULT 'neuvedeno',
  ownership_form ENUM('bezpodílové','společné jmění manželů','podílové','neuvedeno') DEFAULT 'neuvedeno',
  persons_count TINYINT NULL COMMENT 'počet osob žijících v jednotce',
  share_pct DECIMAL(10,9) NULL COMMENT 'procentní podíl na domě',
  note TEXT,
  gdpr_consent TINYINT(1) DEFAULT 0,
  gdpr_date DATETIME NULL,
  status ENUM('úplná','neúplná','chybí') DEFAULT 'chybí',
  vote_stance ENUM('pro','proti','neznámý','') DEFAULT '' COMMENT 'postoj k výboru',
  board_note VARCHAR(200) COMMENT 'interní poznámka výboru',
  updated_by_role VARCHAR(20) NULL,
  locked_by_owner TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Další osoby vázané na vlastníka (např. manžel/manželka)
CREATE TABLE IF NOT EXISTS owner_persons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_id INT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(120),
  phone VARCHAR(30),
  relation VARCHAR(80),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nájemníci
CREATE TABLE IF NOT EXISTS tenants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(120),
  email2 VARCHAR(120),
  primary_email TINYINT(1) DEFAULT 1,
  phone VARCHAR(30),
  phone2 VARCHAR(30),
  primary_phone TINYINT(1) DEFAULT 1,
  rent_from DATE NULL,
  rent_until DATE NULL,
  persons_count TINYINT NULL,
  note TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Orgány SVJ (výbor, kontrolní komise)
CREATE TABLE IF NOT EXISTS committee (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_type ENUM('owner','tenant') NOT NULL DEFAULT 'owner',
  owner_id INT NULL,
  tenant_id INT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(120),
  phone VARCHAR(30),
  role ENUM('predseda','mistopredseda','clen_vyboru','predseda_kk','clen_kk') NOT NULL,
  valid_from DATE NULL,
  valid_until DATE NULL,
  note TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE SET NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nástěnka
CREATE TABLE IF NOT EXISTS posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  pinned TINYINT(1) DEFAULT 0,
  visibility ENUM('verejny','prihlaseni','skryty') NOT NULL DEFAULT 'verejny',
  author_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ankety
CREATE TABLE IF NOT EXISTS polls (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(300) NOT NULL,
  closes_at DATE NULL,
  active TINYINT(1) DEFAULT 1,
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  option_text VARCHAR(200) NOT NULL,
  FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  option_id INT NOT NULL,
  unit_id INT NOT NULL COMMENT 'jedna jednotka = jeden hlas',
  voted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY one_vote (poll_id, unit_id),
  FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
  FOREIGN KEY (option_id) REFERENCES poll_options(id),
  FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Schůze SVJ
CREATE TABLE IF NOT EXISTS meetings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  meeting_date DATE NOT NULL,
  meeting_time TIME NULL,
  location VARCHAR(200),
  agenda TEXT COMMENT 'program schůze',
  quorum_pct DECIMAL(5,2) DEFAULT 50.00 COMMENT 'kvórum pro usnášeníschopnost v %',
  status ENUM('připravuje se','probíhá','ukončeno') DEFAULT 'připravuje se',
  notes TEXT,
  locked TINYINT(1) DEFAULT 0,
  invitation_sent_at DATETIME NULL,
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meeting_agenda_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT NOT NULL,
  order_num TINYINT NOT NULL DEFAULT 1,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  vote_type ENUM('žádné','pro/proti/zdržel se','ano/ne') DEFAULT 'pro/proti/zdržel se',
  FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meeting_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT NOT NULL,
  owner_id INT NOT NULL,
  unit_id INT NOT NULL,
  type ENUM('osobně','plná moc','online') DEFAULT 'osobně',
  proxy_name VARCHAR(120) COMMENT 'jméno zástupce při plné moci',
  arrived_at DATETIME NULL,
  note VARCHAR(200),
  UNIQUE KEY one_per_unit (meeting_id, unit_id),
  FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  FOREIGN KEY (owner_id) REFERENCES owners(id),
  FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meeting_item_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agenda_item_id INT NOT NULL,
  unit_id INT NOT NULL,
  owner_id INT NOT NULL,
  vote ENUM('pro','proti','zdrzelo') NOT NULL,
  UNIQUE KEY one_vote (agenda_item_id, unit_id),
  FOREIGN KEY (agenda_item_id) REFERENCES meeting_agenda_items(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (owner_id) REFERENCES owners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meeting_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agenda_item_id INT NOT NULL,
  vote_pro DECIMAL(10,4) DEFAULT 0 COMMENT 'součet podílů PRO',
  vote_proti DECIMAL(10,4) DEFAULT 0 COMMENT 'součet podílů PROTI',
  vote_zdrzelo DECIMAL(10,4) DEFAULT 0 COMMENT 'součet podílů zdržel se',
  vote_pro_count INT DEFAULT 0,
  vote_proti_count INT DEFAULT 0,
  vote_zdrzelo_count INT DEFAULT 0,
  result ENUM('schváleno','neschváleno','odloženo','') DEFAULT '',
  note VARCHAR(200),
  FOREIGN KEY (agenda_item_id) REFERENCES meeting_agenda_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hlasování mimo schůzi (per rollam)
CREATE TABLE IF NOT EXISTS perrollam (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  closes_at DATETIME NOT NULL,
  status ENUM('aktivni','uzavreno') DEFAULT 'aktivni',
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS perrollam_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  perrollam_id INT NOT NULL,
  order_num TINYINT NOT NULL DEFAULT 1,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  FOREIGN KEY (perrollam_id) REFERENCES perrollam(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS perrollam_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  unit_id INT NOT NULL,
  owner_id INT NOT NULL,
  vote ENUM('pro','proti','zdrzelse') NOT NULL,
  voted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY one_vote (item_id, unit_id),
  FOREIGN KEY (item_id) REFERENCES perrollam_items(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (owner_id) REFERENCES owners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dokumenty ke stažení
CREATE TABLE IF NOT EXISTS documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  category ENUM('smlouvy','pojisteni','bankovni','revize','znalecke','zapisy','ostatni') NOT NULL DEFAULT 'ostatni',
  description TEXT,
  filename VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  filesize INT NULL,
  mime_type VARCHAR(100),
  filename2 VARCHAR(255) NULL,
  original_name2 VARCHAR(255) NULL,
  filesize2 INT NULL,
  mime_type2 VARCHAR(100) NULL,
  visible_to_owners TINYINT(1) DEFAULT 0 COMMENT '1 = vlastnici mohou videt',
  valid_from DATE NULL,
  valid_until DATE NULL,
  uploaded_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Odkazy na sdílené úložiště (Drive apod.)
CREATE TABLE IF NOT EXISTS drive_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  url VARCHAR(500) NOT NULL,
  description VARCHAR(200),
  icon VARCHAR(10) DEFAULT '📁',
  visible_to_owners TINYINT(1) DEFAULT 0,
  order_num INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Spotřeby (studená/teplá voda, teplo – import z Techem)
CREATE TABLE IF NOT EXISTS consumption (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  rok SMALLINT NOT NULL,
  mesic TINYINT NOT NULL,
  typ ENUM('SV','TV','ITN') NOT NULL,
  jednotka VARCHAR(10) NOT NULL,
  hodnota_zacatek DECIMAL(10,3) NULL,
  hodnota_konec DECIMAL(10,3) NULL,
  spotreba DECIMAL(10,3) NOT NULL,
  UNIQUE KEY one_reading (unit_id, rok, mesic, typ),
  FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Výchozí admin účet (heslo: Admin1234 – změňte po prvním přihlášení!)
INSERT INTO users (username, password_hash, role)
VALUES ('vybor', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uSlKkBa.W', 'admin');
