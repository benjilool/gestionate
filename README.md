# GestiÓnate

Sistema web para restaurantes pequeños: pantalla de cocina (KDS) con los pedidos en orden de llegada, descuento automático de stock según la receta y semáforo de tiempos de preparación.

Proyecto de Especialidad TPE401 · Técnico en Programación y Análisis de Sistemas · AIEP La Serena.
Integrantes: Benjamín Mayea (base de datos y backend PHP) · Benjamín Ibacache (interfaz, landing y pruebas).

## Tecnologías
PHP 8 · MySQL / MariaDB (XAMPP) · HTML y CSS responsive · PDO con consultas preparadas.

## Estructura
```
database/   Script de la base de datos (8 tablas, procedimientos, vistas y datos de prueba)
app/        Aplicación PHP
  includes/ conexion.php (PDO), auth.php (sesión, roles, CSRF), header y footer
  css/      estilos.css
landing/    Página de aterrizaje
docs/       Modelo entidad-relación y documentación
```

## Instalación local (XAMPP)
1. Copia la carpeta del repositorio dentro de `C:\xampp\htdocs\` (queda `htdocs\gestionate`).
2. Enciende **Apache** y **MySQL** en el panel de XAMPP.
3. Abre `http://localhost/phpmyadmin` → pestaña **Importar** → elige `database/Base_de_datos.sql` → **Importar**.
4. En `app/`, copia `config.example.php` como `config.php` y revisa los datos (en XAMPP: usuario `root`, sin contraseña).
5. Abre `http://localhost/gestionate/app/`.

## Usuarios de prueba
Contraseña de los tres: `Gestionate2026`

| Correo | Rol | Entra a |
| --- | --- | --- |
| admin@gestionate.cl | administrador | Panel y cocina |
| cocina@gestionate.cl | cocina | Pantalla de cocina |
| garzon1@gestionate.cl | garzón | Panel |

## Seguridad implementada
- Contraseñas con `password_hash()` / `password_verify()` (bcrypt).
- Consultas preparadas con PDO (contra inyección SQL) y `htmlspecialchars` al mostrar datos (contra XSS).
- Token CSRF en todos los formularios, cookie de sesión `HttpOnly` y `SameSite=Strict`.
- Bloqueo de 60 segundos tras 5 intentos fallidos de inicio de sesión.
- Acceso por rol en cada página.
- `config.php` fuera del repositorio.

## Estado
- [x] Base de datos de 8 tablas con cola FIFO y descuento de stock
- [x] Conexión PDO e inicio de sesión con roles
- [x] Pantalla de cocina con semáforo y cambio de estado
- [ ] Toma de pedidos (garzón)
- [ ] CRUD de platos, categorías e ingredientes
- [ ] Registro de entradas de inventario
- [ ] Landing page
