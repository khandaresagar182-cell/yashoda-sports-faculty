> **Historical — Railway is no longer a deployment target.** Production is Namecheap cPanel (see the README's *Deployment* section).
> `scripts/init_database.php` referenced below no longer exists, and the seeded default logins here must be changed on any real deployment.

# Railway Deployment

The portal runs as one PHP/Apache service with one Railway MySQL service.

## 1. Create the project

1. In Railway, choose **New Project** and **Deploy from GitHub repo**.
2. Select `sagaraiml2126/sportsfaculty`.
3. Railway detects the root `Dockerfile` automatically.
4. Add a **MySQL** database to the same Railway project.

## 2. Connect MySQL

In the application service, open **Variables** and add these references. If the
database service has a different name, replace `MySQL` with that service name.

```text
MYSQLHOST=${{MySQL.MYSQLHOST}}
MYSQLPORT=${{MySQL.MYSQLPORT}}
MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}
MYSQLUSER=${{MySQL.MYSQLUSER}}
MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}
APP_ENV=production
AUTO_INIT_DB=1
```

`AUTO_INIT_DB=1` initializes and seeds a blank database. It safely skips
initialization when application tables already exist.

## 3. Persist uploads

Add a volume to the application service with this exact mount path:

```text
/var/www/html/uploads
```

This keeps student photos and uploaded documents across deployments.

## 4. Create the public URL

1. Open the application service.
2. Go to **Settings > Networking**.
3. Click **Generate Domain**.
4. Redeploy once if the generated domain was added after the first deployment.

The app automatically uses `RAILWAY_PUBLIC_DOMAIN` for password-reset links.
You may alternatively set:

```text
SITE_URL=https://your-domain.example
```

## 5. First login

The seed creates these development accounts:

```text
admin / Admin@123
eng_faculty / Faculty@123
poly_faculty / Faculty@123
pharm_faculty / Faculty@123
```

Change every default password immediately after the first production login.
