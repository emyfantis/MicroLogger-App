# MicroLogger App

MicroLogger is a lightweight, fast and secure PHP/MySQL web application  
designed for managing microbiological test logs in food laboratories and  
quality assurance environments.

It provides structured data entry, audit logging, product lookups,  
document generation, statistics dashboards, and a clean modern interface  
optimized for daily laboratory workflows.

This project is part of my work as a **Food Technologist** combining applied  
microbiology with **software development** to modernize internal QC processes.

---

## 🧩 What Problems Does It Solve?

- Eliminates inconsistent Excel-based microbiology logs  
- Improves traceability (full audit log with before/after values)  
- Speeds up data entry with API product caching and quick lookup  
- Generates clean PDF reports for samples, tables and product logs  
- Provides statistics for trends, limits and product QC performance  
- Centralizes user activity tracking (HACCP / ISO 22000 compliance)

---

## 🚀 Features

### ✔ Microbiology Logs  
- Log creation with product, batch code, expiry date  
- Multi-day evaluations (2nd/3rd/4th day)  
- Enterobacteriaceae, TMC 30°C, Yeasts & Molds, Bacillus  
- Stress tests, comments & observations  
- Backdated result entry via incubation calendar  
- PDF export for tables or individual product entries

### ✔ Authentication & Roles  
- Secure login (password hashing & session handling)  
- User management (admins only)  
- Role-based actions (`admin`, `user`)

### ✔ Products Cache  
- Stores ERP/API product entries  
- Enables instant product dropdown search  
- Avoids constant external API requests  
- Ideal for large product catalogs

### ✔ Audit Logging  
- Tracks every insert, update and delete  
- Saves old/new values as JSON  
- Logs IP, timestamp and user agent  
- Ensures traceability for audits (HACCP / ISO 22000)

### ✔ Statistics & Dashboard  
- Product frequency analysis  
- Batch/date breakdowns  
- Table analytics  
- User activity analytics  
- Microbial limit checking  
- SQL indexing for performance

### ✔ Modern UI  
- Custom CSS (animations, tables, buttons, layout)  
- Sidebar dashboard layout  
- Responsive pages  
- Clean typography and design system

---

## 🧰 Technology Stack

- **Backend:** PHP 8.1+  
- **Database:** MySQL 8+  
- **Frontend:** HTML/CSS (custom UI components)  
- **Server:** Apache / Nginx  
- **Extra:** API integrations, PDF generation

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
   Configure:
      - DB credentials  
      - APP_URL
      - API endpoint for products (optional).
   
3. Import the database:

   ```bash
   SOURCE database/schema.sql;
   SOURCE database/add_indexes.sql;

4. Configure your web server
   Point your VirtualHost / Nginx site to the project root.
   
5. Create admin user manually in the database
   (Users table is included in schema.)


## 🗂 Project Structure

```bash
   /actions        → Form handlers (create, update, export)
   /config         → App config, environment loader, validation, audit
   /css            → UI styling system (tables, forms, sidebar, animations)
   /database       → schema.sql + indexing
   /img            → icons, branding
   /logs           → runtime logs (ignored, .gitkeep only)
   /pages          → UI views (dashboard, logs, statistics, users)
   index.php       → Entry point
   app.php         → Core initialization
```

🔒 License

  This project is released under a proprietary license.
  You are NOT permitted to use, modify, copy, distribute, or deploy this
  software without explicit written permission from the author.
  See the [LICENSE](./LICENSE) file.

📬 Contact

For licensing or commercial inquiries, contact:

Yfantis Emmanouil

Email: <manolisifantis99@gmail.com>
