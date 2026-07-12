<div align="center">
  <h1>📚 Freeblioteca</h1>
  <p><strong>El sistema moderno de gestión y lectura de libros potenciado por Inteligencia Artificial</strong></p>
  
  <a href="https://www.freeblioteca.cl"><strong>🌐 Visita el sitio web oficial: freeblioteca.cl</strong></a>
</div>

<br>

> ⚠️ **ACLARACIÓN IMPORTANTE SOBRE EL CÓDIGO DE ESTE REPOSITORIO** ⚠️
> 
> **Este repositorio NO es el proyecto completo de Freeblioteca.** 
> El código fuente completo y la base de datos se mantienen de forma **100% privada** por motivos de seguridad y derechos de autor. 
> 
> Los archivos de código que ves aquí se han subido **a propósito** como un *showcase* arquitectónico público. Son una selección de clases estructurales (como Core Services y comandos de IA) para usar como portafolio y demostrar la tecnología técnica que potencia la plataforma.

## 🌟 ¿Qué es Freeblioteca?

**Freeblioteca** es una plataforma digital de vanguardia construida para democratizar el acceso a la lectura. Cuenta con un catálogo de más de **260,000 libros**, integrando motores de búsqueda híbridos locales con la API de Google Books, y un sistema automatizado de curaduría de metadatos mediante IA.

## 🏗️ Arquitectura y Tecnologías

El ecosistema de Freeblioteca está diseñado para soportar alto tráfico, procesamiento paralelo en segundo plano y una experiencia de usuario extremadamente rápida y fluida:

*   **Framework Principal:** Laravel 12 (PHP 8.2+)
*   **Frontend:** Blade Templates, TailwindCSS v4, AlpineJS
*   **Base de Datos:** MySQL / Eloquent ORM
*   **Búsqueda Híbrida:** `HybridSearchService` (mezcla resultados en caché local con peticiones en vivo a APIs externas).
*   **Procesamiento Asíncrono:** Laravel Queues y Workers paralelos para limpieza masiva de datos.

## 🤖 Curaduría Potenciada por Inteligencia Artificial (IA)

Uno de los mayores retos de manejar bibliotecas masivas es el metadata sucio o incompleto. Freeblioteca utiliza modelos de **Gemini** mediante procesos de *Deep Archival* en segundo plano para sanear la base de datos de manera autónoma:

1.  **Deduplicación Semántica:** Detección de títulos similares con autores idénticos mediante distancia de Levenshtein e IA.
2.  **Rescate de Autores Desconocidos:** Un pipeline de dos etapas que utiliza el título y la descripción del libro para deducir autores históricos o anónimos en lotes de hasta 25,000 libros por iteración.
3.  **Clasificación Dewey Automática:** Asignación de categorías globales (ej: Filosofía, Historia, Infantil) procesando metadatos para ubicar los libros en sus estanterías digitales correctas.

## 📖 Funcionalidades Clave

*   **Vitrina Pública y Estantería Infantil:** Filtros inteligentes que adaptan la interfaz y el catálogo según la edad del lector.
*   **Modo de Lectura Inmersivo:** Lector web integrado con guardado automático de progreso cada 30 segundos (`PersonalHistory`).
*   **Gamificación y Logros:** Sistema de hitos lectores impulsado por Cronicón.
*   **Full SEO & JSON-LD:** Completamente optimizada para indexación orgánica en Google y motores de búsqueda.

## 🤝 Contacto y Comunidad

Freeblioteca es un proyecto en constante evolución. Si deseas colaborar, reportar algún problema, o conocer más detalles sobre la arquitectura interna, contáctame directamente escribiendo a: **yeib@pm.me**

---
*Hecho con ❤️ para la comunidad lectora.*
