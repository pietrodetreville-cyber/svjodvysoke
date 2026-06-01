# SVJ Od Vysoké – portál

## Struktura souborů

```
/
├── index.php              ← přihlašovací stránka
├── logout.php
├── config.php             ← NASTAVTE před nahráním
├── .htaccess
├── install.sql            ← SQL pro vytvoření tabulek
├── includes/
│   ├── functions.php
│   ├── header.php
│   └── footer.php
├── admin/                 ← jen pro výbor (role=admin)
│   ├── dashboard.php
│   ├── owners.php
│   ├── owner_edit.php
│   ├── posts.php
│   ├── polls.php
│   ├── users.php
│   └── units.php
└── owner/                 ← pro vlastníky (role=owner)
    ├── dashboard.php
    ├── profile.php
    ├── posts.php
    └── polls.php
```

## Instalace (krok za krokem)

### 1. Databáze na Webglobu
1. Přihlaste se do Webglobe administrace
2. Vytvořte novou MySQL databázi (zapamatujte si: název, uživatel, heslo)
3. Otevřete **phpMyAdmin** → vyberte databázi → záložka **SQL**
4. Vložte obsah souboru `install.sql` a klikněte **Spustit**

### 2. Konfigurace
Otevřete `config.php` a vyplňte:
```php
define('DB_NAME', 'nazev_vasi_databaze');
define('DB_USER', 'uzivatel_databaze');
define('DB_PASS', 'heslo_databaze');
define('SITE_URL', 'https://odvysoke.drymtym.cz');
define('SECRET_KEY', 'vygenerujte-nahodny-retezec-32-znaku');
```

### 3. Nahrání na server
Nahrajte celý obsah složky přes **FTP** (FileZilla apod.) nebo přes
Webglobe správce souborů do kořenové složky subdomény
`odvysoke.drymtym.cz`.

### 4. Subdoména na Webglobu
V administraci Webglobu: **Domény → Subdomény → Přidat subdoménu**
- Subdoména: `odvysoke`
- Doména: `drymtym.cz`
- Cílová složka: složka, kam jste nahráli soubory

### 5. První přihlášení
- URL: `https://odvysoke.drymtym.cz`
- Jméno: `vybor`
- Heslo: `Admin1234`
- **Ihned změňte heslo** v sekci Uživatelé!

## Postup spuštění portálu

1. Přidejte jednotky: **Admin → Jednotky** (všechny byty, garáže, sklepy)
2. Vytvořte účty pro vlastníky: **Admin → Uživatelé → Nový účet**
   - Přiřaďte každému účtu jeho jednotku
3. Každý vlastník se přihlásí a vyplní svou kartu (**Moje karta**)
4. Výbor vidí v **Kartotéce** stav vyplnění + může upravovat
5. Přidávejte příspěvky na **Nástěnku** a vytvářejte **Ankety**

## Výchozí heslo pro SQL

Heslo `Admin1234` je v `install.sql` uloženo jako bcrypt hash.
Po přihlášení jděte na **Admin → Uživatelé** a heslo si změňte.

## Poznámky k GDPR

- Souhlas se eviduje s datem a časem
- Data vlastníků jsou viditelná pouze přihlášeným admin uživatelům
- Stránka s formulářem obsahuje popis účelu zpracování
- Doporučujeme přidat odkaz na zpracování osobních údajů dle § 13 GDPR
