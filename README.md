# MicrobiologyApp

MicrobiologyApp is a lightweight, fast, and secure PHP/MySQL web application 
designed for managing microbiological test logs within food laboratories 
and quality assurance environments.

It offers structured data entry, audit logging, product lookup caching, 
document generation, statistics dashboards, and a clean modern interface 
optimized for daily use.

> **License Notice:**  
> This project is released under a **proprietary license**.  
> You are NOT permitted to use, modify, copy, distribute, or deploy this 
> software without explicit written permission from the author.  
> See the [LICENSE](./LICENSE) file for details.

---

## 🚀 Features

### ✔ Microbiology Logs  
- Adds logs using informations like:
   Table name, incubation profiles, sampling info  
   Product, batch code, expiry date  
   Enterobacteriaceae, TMC 30°C, Yeasts & Molds, Bacillus  
   Multi-day evaluation fields (2nd/3rd/4th day)  
   Comments, stress tests, observations
- Uses the Incubation calendar & Adds microbial results to past logs 
- PDF export for individual products or tables

### ✔ Authentication & Roles  
- Secure login  
- Password hashing (`password_hash`)  
- User roles (`admin`, `user`)
- User management page for admins

### ✔ Products Cache  
- Stores product entries from external APIs  
- Faster dropdown search  
- Avoids repeated API calls  
- Ideal for integration with ERP systems

### ✔ Audit Logging  
- Tracks all insert, update and delete operations  
- Saves old + new values in JSON  
- Includes IP, timestamp, user agent  
- Ensures traceability and compliance (HACCP / ISO 22000)

### ✔ Statistics & Dashboard  
- Product frequency charts  
- Batch/date analysis  
- Table-by-table breakdown
- Incubation calendar 
- Statistics and performance per product with microbial limits & thresholds
- Statistics for user related activity
- Optimized with additional SQL indexes

### ✔ Modern UI  
- Sidebar-based dashboard  
- Custom CSS (animations, tables, buttons)  
- Responsive layout  
- Clean typography and color palette

---

## 📦 Requirements

- PHP 8.1+  
- MySQL 8.0+  
- Apache / Nginx  
- Composer (if extending features)

---

## 🛠 Installation

1. Clone the repository:

   ```bash
   git clone https://github.com/<your-username>/<your-repo>.git
   cd <your-repo>
  
2. Copy the environment template:

   ```bash
   cp .env.example .env
   ```
   Then edit .env with your database credentials.
   
3. Import the database:

   ```bash
   SOURCE database/schema.sql;
   SOURCE database/add_indexes.sql;

4. Point your web server (VirtualHost) to the project root.
   
5. Login as the admin user you created manually in the DB.


## 🗂 Project Structure

```bash
  /actions
  /config
  /css
  /database
  /img
  /logs
  /pages
  app.php
  index.php
  README.md
  LICENSE
```

🔒 License

  This project is proprietary and all rights reserved.
  Unauthorized use is strictly prohibited.
  See the [LICENSE](./LICENSE) file.

📬 Contact

For licensing or commercial inquiries, contact:

Yfantis Emmanouil

Email: <manolisifantis99@gmail.com>
