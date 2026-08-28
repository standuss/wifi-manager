# WiFi Manager

Česká webová administrace pro RouterOS 7 WiFi CAPsMAN (`/interface/wifi`). Spravuje přístupové body, klienty, registrace zařízení, Wi‑Fi sítě, dlouhodobý syslog/CEF a IPFIX. Je určená pro opakované instalace u různých zákazníků.

Repozitář neobsahuje žádná hesla, databáze ani údaje konkrétního zákazníka. Každá instalace si vytvoří vlastní šifrovací klíč a lokální konfiguraci.

## Rychlá instalace

Požadavky: Debian 13, statická IP adresa serveru, přístup `root` a připojení k internetu. Podporované jsou ARM64 i x86_64.

Na čistém serveru spusťte:

```bash
apt-get update && apt-get install -y git
git clone https://github.com/standuss/wifi-manager.git
cd wifi-manager
sudo ./install.sh
```

Instalátor:

- doinstaluje Apache, PHP, SQLite, rsyslog, nfdump/nfcapd a potřebné nástroje;
- zkopíruje aplikaci do `/var/www/wifimanager`;
- interaktivně vytvoří první administrátorský účet;
- automaticky zjistí IP adresu serveru;
- zapne synchronizační, logovací, retenční a aktualizační služby;
- nastaví pevný veřejný aktualizační kanál `standuss/wifi-manager` bez GitHub účtu a tokenu.

Po dokončení otevřete adresu vypsanou instalátorem, obvykle:

```text
http://IP_ADRESA_SERVERU/wifimanager
```

HTTPS a veřejné zpřístupnění nejsou součástí automatické instalace. Před přístupem z internetu použijte vlastní HTTPS reverse proxy nebo odpovídající zabezpečení.

## První nastavení

V aplikaci otevřete **Nastavení** a vyplňte:

1. adresu MikroTiku, API port `8728`, uživatele a heslo;
2. provozní a registrační VLAN;
3. názvy DHCP serverů, statický IP rozsah a výchozí rychlosti;
4. tlačítko **Uložit a otestovat spojení**.

Na MikroTiku povolte běžné API pouze z IP adresy serveru WiFi Manageru:

```routeros
/ip service set [find where name=api] disabled=no port=8728 address=SERVER_IP/32
```

Port `8728` není šifrovaný. Nikdy jej nepovolujte z internetu ani z nedůvěryhodných sítí.

## Funkce

- role administrátor a pouze prohlížení;
- SQLite v režimu WAL a šifrované uložení přístupových údajů pomocí Sodium;
- automatická synchronizace klientů, DHCP lease, Access List, Simple Queues, Wi‑Fi konfigurací a CAPů;
- registrace zařízení jednou operací včetně VLAN, statického DHCP a rychlostního omezení;
- vytváření, zapínání a vypínání Wi‑Fi sítí pro 2,4/5 GHz;
- zobrazení Wi‑Fi hesla oprávněnému administrátorovi s auditním záznamem;
- dlouhodobý syslog/CEF archiv v denních JSONL souborech;
- příjem a vyhledávání IPFIX pomocí `nfcapd` a `nfdump`;
- vazba historické IP adresy na zařízení a držitele;
- automatické nastavení CEF/syslogu a IPFIX přímo na MikroTiku, včetně oddělené NAT adresy a portů;
- detailní prohlížení toků včetně MAC adres, NAT překladu, rozhraní a souhrnů;
- SMTP s volitelným ověřením a šifrováním, e-mailová upozornění podle uživatele;
- šifrované ruční i pravidelné zálohy RouterOS s retencí a stažením z administrace;
- správa retence a diskových limitů z české administrace;
- aktualizace na kliknutí s kontrolou původu balíčku, zálohou a návratem při chybě.

## Syslog, CEF a IPFIX

Instalátor zapne tyto výchozí porty:

- TCP `5514` – CEF z novějšího RouterOS;
- UDP `514` – kompatibilní syslog;
- UDP `2055` – IPFIX z gateway.

V **Služby a aktualizace** vyplňte místní naslouchací IP a zvlášť IP adresu, kterou vidí MikroTik. Po uložení aplikace sama vytvoří RouterOS logging action, pravidla pro běžné systémové události a IPFIX target. Pokud je server za NAT, veřejné cílové porty mohou být jiné než místní; ve formuláři jsou proto uvedené odděleně. Na NATu je následně přesměrujte na místní TCP/UDP porty serveru. Plošné firewall a debug logování aplikace nezapíná.

Výchozí retence je 1825 dní, diskový limit 60 GiB pro syslog a 280 GiB pro IPFIX. Hodnoty lze změnit v **Služby a aktualizace**. Při překročení stáří nebo limitu se nejdřív mažou nejstarší archivy.

Kontrola služeb:

```bash
systemctl status apache2 wifimanager-worker rsyslog wifimanager-nfcapd --no-pager
systemctl list-timers wifimanager-retention.timer --no-pager
ss -lntup | grep -E ':(514|2055|5514)\b'
```

## Aktualizace bez nastavování GitHubu

Adresa veřejného repozitáře je součástí aplikace. Zákaznická instalace nevyžaduje GitHub účet, token ani zadání URL. Administrátor v **Služby a aktualizace** pouze klikne na **Zkontrolovat** a následně na **Aktualizovat**.

Release balíček a jeho podepsaný doklad původu se stahují přes HTTPS. Aktualizační služba ověří repozitář, tag, GitHub Actions workflow a shodu artefaktu; potom zkontroluje PHP, zazálohuje kód i SQLite a provede migraci. `config/local.php`, databáze a archivy v `/var/lib/wifimanager` se aktualizací nepřepisují.

## Vydání nové verze

Správce projektu změní `VERSION` a odešle změny do větve `main`:

```bash
git add .
git commit -m "Release 0.3.0"
git push origin main
```

GitHub Actions při nové hodnotě `VERSION` vytvoří tag, ZIP, doklad původu a veřejný GitHub Release. Zákaznické instalace pak novou verzi nabídnou automaticky. Běžné změny bez zvýšení verze nový release nevytvoří.

## Zálohování

Zálohujte:

- `/var/www/wifimanager/config/local.php` – aplikační šifrovací klíč;
- `/var/www/wifimanager/storage/wifimanager.sqlite` – účty, evidence a historie;
- `/var/lib/wifimanager` – dlouhodobý syslog a IPFIX archiv.

Samotné RouterOS zálohy lze zapnout v **Služby a aktualizace → Zálohy RouterOS**. Soubory jsou šifrované heslem zadaným administrátorem a ukládají se do `/var/lib/wifimanager/backups`.

Bez původního aplikačního klíče nelze obnovit zašifrované přístupové údaje.

## Bezpečnostní model

Web běží jako `www-data` bez práv roota. Systémové změny a aktualizace provádějí omezené systemd jednotky přes přesně definované fronty. MikroTik zůstává zdrojem skutečné síťové konfigurace; při výpadku WiFi Manageru Wi‑Fi, DHCP, VLAN i fronty pokračují s posledním nastavením.
