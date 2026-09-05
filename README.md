# Lucuma TRP Bridge

Expone la base de traducciones de **TranslatePress** por REST, para poder leer los
originales que TranslatePress ha registrado y publicar traducciones revisadas sin
pasar por el editor visual.

Nace de una limitación concreta: TranslatePress guarda las traducciones en las tablas
`wp_trp_dictionary_*` y **no ofrece ninguna ruta REST** para escribirlas.

## Instalación

Despliegue por Git a `wp-content/plugins/lucuma-trp-bridge/`, y activar desde
Plugins. También funciona como *must-use plugin*: basta con dejar el `.php` suelto
en `wp-content/mu-plugins/` y se carga solo.

Requiere TranslatePress activo y al menos un idioma de traducción configurado.

## Autenticación

Todas las rutas exigen la capacidad `manage_options`. Se usan con una contraseña de
aplicación de un usuario administrador:

```bash
curl -u "USUARIO:APP PASSWORD" https://TU-SITIO/wp-json/lucuma-trp/v1/diag
```

## Rutas

Todas bajo el namespace `lucuma-trp/v1`.

### `GET /diag`

Radiografía de la instalación: versión de TranslatePress, idioma por defecto, idiomas
configurados, motor de traducción automática, y todas las tablas `trp_*` con sus
columnas, número de filas y reparto por estado.

### `GET /strings`

Lee el diccionario de un idioma.

| Parámetro | Tipo | Notas |
|---|---|---|
| `language` | string | **Obligatorio.** Código configurado, p. ej. `es_ES` |
| `limit` | int | 1 a 1000, por defecto 100 |
| `offset` | int | Paginación |
| `search` | string | Filtra por coincidencia parcial en `original` |
| `status` | int | `0` sin traducir · `1` traducción automática · `2` revisado |

### `POST /translate`

Publica traducciones. Máximo 500 pares por llamada.

```json
{
  "language": "es_ES",
  "status": 2,
  "dry_run": false,
  "pairs": [
    { "original": "Breath is the quiet companion...", "translated": "La respiración es la compañera silenciosa..." }
  ]
}
```

Devuelve `actualizados`, `sin_cambio`, `no_encontrados` y `errores`.

## Dos decisiones de diseño

**Solo actualiza originales que ya existen; nunca los inventa.**

TranslatePress crea la fila del original cuando alguien visita la página en el idioma
traducido. Un original insertado a mano que no coincida carácter por carácter con lo
que renderiza el sitio quedaría muerto en la base de datos sin llegar a mostrarse
nunca. Por eso las cadenas que no casan se devuelven en `no_encontrados` en lugar de
crear filas huérfanas.

El orden de trabajo que se deduce de esto: recorrer las URLs traducidas para que
TranslatePress registre los originales, leerlos con `/strings`, y solo entonces
escribir contra la cadena real.

**Escribe `status = 2` por defecto, revisado por humano.**

Es el estado que TranslatePress respeta: la traducción automática no lo sobrescribe.
Las traducciones escritas a mano quedan blindadas frente al motor.

## Extras

- `dry_run: true` simula la tanda completa sin escribir nada.
- Al terminar una escritura purga la caché de WP Rocket si está presente, porque si no
  se seguiría sirviendo la versión anterior.
