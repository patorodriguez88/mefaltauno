<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>MeFaltaUno · Muy pronto</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&family=Nunito:wght@400;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --mfu-teal: #006368;
            --mfu-teal-dark: #004b4f;
            --mfu-yellow: #f1e52f;
            --mfu-white: #ffffff;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            background: var(--mfu-teal);
            color: var(--mfu-white);
            font-family: 'Inter', system-ui, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px 16px;
        }
        .logo { width: min(420px, 85vw); height: auto; }
        h1 {
            font-family: 'Nunito', sans-serif;
            font-weight: 800;
            font-size: clamp(1.6rem, 5vw, 2.6rem);
            margin: 32px 0 8px;
        }
        h1 span { color: var(--mfu-yellow); }
        p { margin: 0 auto; max-width: 480px; line-height: 1.5; opacity: .85; }
        .btn {
            display: inline-block;
            margin-top: 28px;
            padding: 12px 24px;
            border-radius: 999px;
            background: var(--mfu-yellow);
            color: var(--mfu-teal-dark);
            font-weight: 700;
            text-decoration: none;
        }
        .btn:hover { filter: brightness(1.05); }
        footer { margin-top: 48px; font-size: .8rem; opacity: .6; }
    </style>
</head>
<body>
    <img class="logo" src="assets/img/logo.jpg" alt="MeFaltaUno">
    <h1>Estamos armando <span>algo nuevo</span></h1>
    <p>Muy pronto vas a poder ver nuestras colecciones, comics y modelismo en la nueva tienda de MeFaltaUno.</p>
    <a class="btn" href="https://mefaltauno.com.ar">Ir a la tienda</a>
    <footer>&copy; <?= date('Y') ?> MeFaltaUno · Dinter S.A.</footer>
</body>
</html>
