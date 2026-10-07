# MeFaltaUno

E-commerce de MeFaltaUno (Dinter S.A.): colecciones, comics y modelismo.

- **Local:** http://localhost/mefaltauno/
- **Desarrollo:** https://web.mefaltauno.com.ar (deploy automático por FTP al hacer push a `main`)
- **Producción actual:** https://mefaltauno.com.ar (Tiendanube, hasta el lanzamiento)

## Deploy
`.github/workflows/main.yml` sincroniza el repo por FTP con `user@web.mefaltauno.com.ar`
(raíz del subdominio). La contraseña va en el secret `FTP_PASSWORD` del repo.
