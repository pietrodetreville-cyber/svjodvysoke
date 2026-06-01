-- SVJ Od Vysoké – databázová struktura
-- Spusťte v phpMyAdmin nebo MySQL klientu

SET NAMES utf8mb4;
SET time_zone = '+01:00';

-- Uživatelé (výbor = admin, vlastníci = owner)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','owner') NOT NULL DEFAULT 'owner',
  unit_id INT NULL,
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
  share_denominator INT NULL COMMENT 'podíl – jmenovatel'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kartotéka vlastníků
CREATE TABLE IF NOT EXISTS owners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(120),
  phone VARCHAR(30),
  address VARCHAR(200) COMMENT 'korespondenční adresa, pokud se liší',
  residence ENUM('trvalé','pronájem','druhé bydliště','neuvedeno') DEFAULT 'neuvedeno',
  note TEXT,
  gdpr_consent TINYINT(1) DEFAULT 0,
  gdpr_date DATETIME NULL,
  status ENUM('úplná','neúplná','chybí') DEFAULT 'chybí',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nástěnka
CREATE TABLE IF NOT EXISTS posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  pinned TINYINT(1) DEFAULT 0,
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

-- Výchozí admin účet (heslo: Admin1234 – změňte po prvním přihlášení!)
INSERT INTO users (username, password_hash, role)
VALUES ('vybor', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uSlKkBa.W', 'admin');
