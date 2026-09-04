# Mi Dinero – PHP + MySQL + Realtime

Dashboard familiar de ingresos y egresos con actualización en tiempo real mediante **Server-Sent Events (SSE)**.

## Incluye
- Dashboard por mes.
- Comparativa contra mes anterior.
- Ingresos, egresos, balance y tasa de ahorro.
- Gastos por categoría.
- Detección de **gastos hormiga**.
- Los gastos de categoría comida entre 19:00 y 02:00 se marcan automáticamente como gasto hormiga.
- Categorías y conceptos configurables.
- Pagos mensuales/obligaciones y resumen de lo que falta pagar.
- Botón **Pagar**: crea el egreso y marca la obligación como pagada.
- Metas para mes actual y próximo mes.
- Email al registrar ingresos/egresos.
- Historial mensual.
- Responsive.
- Login y CSRF.

## Instalación
1. Crea una base de datos MySQL, por ejemplo `finanzas_realtime`.
2. Copia `config.example.php` como `config.php` y coloca host, base, usuario y contraseña.
3. Sube la carpeta al hosting.
4. Abre `/install.php` y crea el usuario administrador.
5. Entra por `/public/login.php`.

## Email
El MVP utiliza `mail()` de PHP. Tu hosting debe tenerlo habilitado. Para entrega confiable por SMTP, reemplaza `app/Mailer.php` por PHPMailer y configura SMTP.

## Realtime
El navegador abre `api/stream.php` con `EventSource`. Cada alta de movimiento, pago o cambio relevante genera un registro en `realtime_events`. El SSE notifica a todas las sesiones abiertas del mismo usuario y el dashboard se refresca automáticamente.

## Precargado
- Alquiler de la casa
- Mantenimiento de la casa
- Luz
- Internet WIN
- Celular Andrés
- Celular Lisset
- Colegio de Nano
- Compra en tienda
- Pedido de comida nocturno
- Ingreso principal

Los montos de pagos fijos quedan inicialmente en **0** para que los definas desde Configuración.
