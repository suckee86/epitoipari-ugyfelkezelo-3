# Építőipari ügyfélkezelő rendszer – Telepítési útmutató

## 📦 Követelmények

- PHP 7.4+ vagy újabb
- MySQL/MariaDB
- XAMPP (vagy más webszerver környezet)
- Composer (opcionális, PDF generáláshoz)

---

## 🛠️ Telepítés lépései

1. **Másold a fájlokat:**
   - A `htdocs` mappát másold a XAMPP `htdocs` könyvtárába.

2. **Importáld az adatbázist:**
   - Nyisd meg a `phpMyAdmin` felületet.
   - Hozd létre az `epitoipari_ugyfelkezelo` nevű adatbázist.
   - Importáld az `install.sql` fájlt.

3. **Jelentkezz be:**
   - Nyisd meg a böngészőben: [http://localhost/epitoipari_ugyfelkezelo_v01/index.php](http://localhost/epitoipari_ugyfelkezelo_v01/index.php)
   - Bejelentkezéshez használható tesztadmin:
     - **Email:** admin@example.com
     - **Jelszó:** admin123

4. **Aláírás feltöltés:**
   - Felmérőként jelentkezve tölts fel egy `.png` formátumú aláírást.

5. **Projekt létrehozás és sablon választás:**
   - Projekt létrehozásakor válaszd ki az Árajánlat vagy Megállapodás sablont.

---

## 🎯 Jövőbeli bővítési lehetőségek

- PDF automatikus generálás
- Több aláírás kezelése
- Kivitelezői és auditor modulok
- Felhasználói jogosultság részletezése

---

## 📝 Kapcsolat

Fejlesztő: [Te]  
Email: suckee86@gmail.com  
