# Comando de Backup Solr - Documentación

## Overview

El comando `solr:backup` permite realizar copias de seguridad completas de datos Solr con soporte para múltiples formatos de exportación, cursor pagination para grandes datasets, y almacenamiento en storage privado.

## Características Principales

- ✅ **Múltiples formatos**: JSON, CSV, XML
- ✅ **Cursor pagination**: Manejo eficiente de grandes datasets
- ✅ **Eliminación de _version_**: Remueve automáticamente el campo `_version_`
- ✅ **Compresión**: Soporte para gzip
- ✅ **Almacenamiento privado**: Guarda backups en `storage/app/private/backups/`
- ✅ **Metadata**: Genera archivos de metadata con estadísticas
- ✅ **Progreso en tiempo real**: Indicadores de progreso durante el backup
- ✅ **Manejo de errores**: Recuperación y reporte de errores

## Sintaxis del Comando

```bash
php solr-cli solr:backup 
    [engine]                          # Motor Solr (default: local)
    --query="*:*"                     # Consulta de filtrado
    --format=json                     # Formato: json|csv|xml
    --output=filename                 # Nombre de archivo personalizado
    --batch=1000                      # Tamaño de batch para cursor
    --compress                        # Comprimir con gzip
    --exclude-version=1               # Excluir campo _version_
    --fields=*                        # Lista de campos específicos
```

## Ejemplos de Uso

### 1. Backup completo en JSON
```bash
php solr-cli solr:backup
```

### 2. Backup con consulta personalizada
```bash
php solr-cli solr:backup --query="category:books AND year:2023"
```

### 3. Backup en formato CSV
```bash
php solr-cli solr:backup --format=csv --output=books_backup
```

### 4. Backup comprimido
```bash
php solr-cli solr:backup --compress --query="id:book*"
```

### 5. Backup con campos específicos
```bash
php solr-cli solr:backup --fields=id,title,author,isbn --output=selected_fields
```

### 6. Backup usando motor diferente
```bash
php solr-cli solr:backup production --query="status:active" --compress
```

## Estructura de Archivos

### Directorios de Backup
```
storage/app/private/
├── solr_engines.json              # Configuración de motores
└── backups/
    ├── json/                      # Backups JSON
    │   ├── backup_local_20250105_143022.json
    │   └── backup_production_20250105_150105.json.gz
    ├── csv/                       # Backups CSV
    │   └── backup_local_20250105_143022.csv
    ├── xml/                       # Backups XML
    │   └── backup_local_20250105_143022.xml
    └── metadata/                  # Metadata de backups
        ├── backup_local_20250105_143022_meta.json
        └── backup_index.json
```

### Formato de Archivo JSON
```json
{
  "backup_metadata": {
    "engine": "local",
    "timestamp": "2026-01-05T15:26:27.557Z",
    "query": "*:*",
    "format": "json",
    "batch_size": 1000,
    "fields_excluded": ["_version_"]
  },
  "response": {
    "docs": [
      {
        "id": "0001",
        "title": "Teoría del Financiamiento",
        "author": ["Drimer, Roberto"],
        "publisher": ["Librería Editorial"],
        "topic": ["administración"],
        "isbn": ["978-987-1577-44-6"],
        "publishDate": ["2011"],
        "collection": ["IUCE"],
        "format": ["Libro"]
      }
    ]
  }
}
```

### Formato de Archivo CSV
```csv
title,author,publisher,id,topic,isbn,publishDate,collection,format
"Teoría del Financiamiento","Drimer, Roberto","Librería Editorial",0001,administración,978-987-1577-44-6,2011,IUCE,Libro
```

### Formato de Archivo XML
```xml
<?xml version="1.0" encoding="UTF-8"?>
<backup>
  <metadata>
    <engine>local</engine>
    <timestamp>2026-01-05T16:57:40.306Z</timestamp>
    <query>id:0001</query>
    <format>xml</format>
    <batch_size>1000</batch_size>
  </metadata>
  <documents>
    <doc>
      <title>Teoría del Financiamiento</title>
      <author>Drimer, Roberto</author>
      <id>0001</id>
      <topic>administración</topic>
    </doc>
  </documents>
</backup>
```

### Archivo de Metadata
```json
{
  "backup_file": "backups/json/test_backup.json",
  "engine": "local",
  "format": "json",
  "query": "*:*",
  "total_documents": 31645,
  "batches_processed": 32,
  "execution_time_ms": 15432.8,
  "compressed": false,
  "fields_excluded": ["_version_"],
  "created_at": "2026-01-05T17:05:13.794Z",
  "errors": []
}
```

## Características Técnicas

### Cursor Pagination
- Usa `cursorMark` para navegación eficiente
- Requiere campo `sort` (default: `id asc`)
- Maneja datasets de cualquier tamaño sin límites de memoria

### Eliminación de Campo _version_
- Por defecto: `_version_` es eliminado de todos los formatos
- Controlable con `--exclude-version=0` para incluirlo
- Optimización para reducir tamaño y limpiar datos

### Compresión gzip
- Nivel de compresión máximo (9)
- Genera archivos `.gz`
- Ahorra hasta 90% de espacio en backups grandes

### Manejo de Errores
- Reintentos automáticos para errores de red
- Reporte detallado de errores en metadata
- Continúa el backup cuando es posible

## Rendimiento

### Métricas Típicas
- **Pequeño dataset** (< 1000 docs): < 1 segundo
- **Mediano dataset** (10K-100K docs): 10-60 segundos
- **Grande dataset** (> 100K docs): Variable según red y server

### Factores de Rendimiento
- **Tamaño de batch**: Default 1000 (ajustable)
- **Latencia de red**: Afecta cada petición
- **Tamaño de documentos**: Afecta transferencia
- **Compresión**: Añade tiempo pero reduce espacio

## Configuración

### Motores Solr
Los motores se configuran en `storage/app/private/solr_engines.json`:

```json
{
  "local": {
    "host": "http://localhost",
    "port": "8985",
    "core": "biblio",
    "auth": null
  },
  "production": {
    "host": "http://prod-server",
    "port": "8983",
    "core": "products",
    "auth": ["user", "password"]
  }
}
```

### Personalización
- **Batch size**: Ajustar según rendimiento y memoria
- **Formatos**: Se pueden agregar nuevos formatos modificando el código
- **Storage**: La ruta `storage/app/private/` es configurable

## Solución de Problemas

### Errores Comunes

#### 1. "Solr engine not found"
```bash
# Verificar configuración
cat storage/app/private/solr_engines.json

# Listar motores disponibles
php solr-cli solr:ping
```

#### 2. "Cursor not supported"
```bash
# Asegurar que el core soporte cursor
php solr-cli solr:query --query="*:*" --rows=1
```

#### 3. "Cannot create output file"
```bash
# Verificar permisos
chmod 755 storage/app/private/
chmod 755 storage/app/private/backups/
```

#### 4. "Timeout errors"
```bash
# Aumentar timeout o reducir batch size
php solr-cli solr:backup --batch=500
```

### Depuración

#### Ver Logs del Proceso
```bash
php solr-cli solr:backup --query="id:0001" --output=debug_test
```

#### Revisar Metadata
```bash
cat storage/app/private/backups/metadata/backup_*
```

#### Validar Archivo de Backup
```bash
# Para JSON
python -m json.tool storage/app/private/backups/json/backup.json

# Para CSV
head -5 storage/app/private/backups/csv/backup.csv

# Para XML
xmllint --format storage/app/private/backups/xml/backup.xml
```

## Integración con otros Sistemas

### Scripts de Automatización
```bash
#!/bin/bash
# Backup diario con compresión
DATE=$(date +%Y%m%d)
php solr-cli solr:backup --compress --output="daily_backup_$DATE"
```

### Monitoreo
```bash
# Ver tamaño de backups
du -sh storage/app/private/backups/

# Contar backups por formato
find storage/app/private/backups/ -name "*.json" | wc -l
find storage/app/private/backups/ -name "*.csv" | wc -l
```

## Buenas Prácticas

### 1. Programación Regular
```bash
# Cron job diario a las 2 AM
0 2 * * * cd /ruta/al/proyecto && php solr-cli solr:backup --compress
```

### 2. Limpieza de Backups Antiguos
```bash
# Eliminar backups más antiguos de 30 días
find storage/app/private/backups/ -name "*.json" -mtime +30 -delete
find storage/app/private/backups/ -name "*.csv" -mtime +30 -delete
```

### 3. Validación Post-Backup
```bash
# Script de validación
php solr-cli solr:backup --query="id:0001" --output=validation_test
if [ $? -eq 0 ]; then
    echo "Backup validado correctamente"
fi
```

### 4. Monitoreo de Espacio
```bash
# Alertar si el storage excede 10GB
SPACE=$(du -s storage/app/private/backups/ | cut -f1)
if [ $SPACE -gt 10485760 ]; then
    echo "Alerta: espacio de backups > 10GB"
fi
```

## Actualizaciones y Mantenimiento

### Versión Actual
- **Versión**: 1.0.0
- **Compatible con**: Laravel Zero 12.0.2+, PHP 8.2+
- **Requiere**: Guzzle HTTP Client

### Mejoras Futuras
- [ ] Soporte para export a bases de datos
- [ ] Paralelización de backups
- [ ] Incremental backups
- [ ] Integración con cloud storage
- [ ] Interfaz web de gestión

## Soporte

Para reportar problemas o solicitar mejoras:

1. Verificar logs de metadata
2. Proporcionar configuración del motor
3. Incluir query utilizada
4. Adjuntar mensajes de error completos

---

**Nota**: Este comando está diseñado para trabajar con índices Solr que soporten cursor pagination. Para versiones antiguas de Solr, considere actualizar el servidor o usar métodos de paginación tradicionales.