package ch.papiersammlung.app

import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject

/**
 * Offline-Puffer für GPS-Punkte.
 * Wenn keine Netzwerkverbindung besteht, werden GPS-Positionen lokal gespeichert
 * und bei Wiederherstellung der Verbindung automatisch nachgesendet (SyncService).
 *
 * Verwendet SQLite direkt (kein Room) um Abhängigkeiten minimal zu halten.
 */
class OfflineBuffer(context: Context) : SQLiteOpenHelper(context, "gps_buffer.db", null, 2) {

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("""
            CREATE TABLE IF NOT EXISTS gps_buffer (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                token        TEXT NOT NULL,
                collection_id TEXT NOT NULL,
                lat          REAL NOT NULL,
                lng          REAL NOT NULL,
                speed        REAL,
                snap_lat     REAL,
                snap_lng     REAL,
                recorded_at  INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            )
        """)
        db.execSQL("CREATE INDEX IF NOT EXISTS idx_token ON gps_buffer(token, collection_id, recorded_at)")
    }

    override fun onUpgrade(db: SQLiteDatabase, old: Int, new: Int) {
        db.execSQL("DROP TABLE IF EXISTS gps_buffer")
        onCreate(db)
    }

    /** Puffert einen GPS-Punkt lokal */
    suspend fun buffer(
        token: String, collectionId: String,
        lat: Double, lng: Double, speed: Double?,
        snapLat: Double? = null, snapLng: Double? = null
    ) = withContext(Dispatchers.IO) {
        val db = writableDatabase
        val stmt = db.compileStatement(
            "INSERT INTO gps_buffer (token,collection_id,lat,lng,speed,snap_lat,snap_lng) VALUES (?,?,?,?,?,?,?)"
        )
        stmt.bindString(1, token)
        stmt.bindString(2, collectionId)
        stmt.bindDouble(3, lat)
        stmt.bindDouble(4, lng)
        if (speed != null) stmt.bindDouble(5, speed) else stmt.bindNull(5)
        if (snapLat != null) stmt.bindDouble(6, snapLat) else stmt.bindNull(6)
        if (snapLng != null) stmt.bindDouble(7, snapLng) else stmt.bindNull(7)
        stmt.executeInsert()
        stmt.close()
    }

    data class BufferedPoint(
        val id: Long,
        val token: String,
        val collectionId: String,
        val lat: Double,
        val lng: Double,
        val speed: Double?,
        val snapLat: Double?,
        val snapLng: Double?
    )

    /** Lädt alle gepufferten Punkte (älteste zuerst, max 200 auf einmal) */
    suspend fun getPending(limit: Int = 200): List<BufferedPoint> = withContext(Dispatchers.IO) {
        val db = readableDatabase
        val result = mutableListOf<BufferedPoint>()
        val cursor = db.rawQuery(
            "SELECT id,token,collection_id,lat,lng,speed,snap_lat,snap_lng FROM gps_buffer ORDER BY recorded_at ASC LIMIT ?",
            arrayOf(limit.toString())
        )
        while (cursor.moveToNext()) {
            result.add(BufferedPoint(
                id           = cursor.getLong(0),
                token        = cursor.getString(1),
                collectionId = cursor.getString(2),
                lat          = cursor.getDouble(3),
                lng          = cursor.getDouble(4),
                speed        = if (cursor.isNull(5)) null else cursor.getDouble(5),
                snapLat      = if (cursor.isNull(6)) null else cursor.getDouble(6),
                snapLng      = if (cursor.isNull(7)) null else cursor.getDouble(7)
            ))
        }
        cursor.close()
        result
    }

    /** Löscht erfolgreich gesendete Punkte */
    suspend fun deleteIds(ids: List<Long>) = withContext(Dispatchers.IO) {
        if (ids.isEmpty()) return@withContext
        val db = writableDatabase
        val placeholders = ids.joinToString(",") { "?" }
        db.execSQL("DELETE FROM gps_buffer WHERE id IN ($placeholders)", ids.toTypedArray())
    }

    /** Anzahl gepufferter Punkte */
    suspend fun count(): Long = withContext(Dispatchers.IO) {
        val db = readableDatabase
        val cursor = db.rawQuery("SELECT COUNT(*) FROM gps_buffer", null)
        val n = if (cursor.moveToFirst()) cursor.getLong(0) else 0L
        cursor.close()
        n
    }

    /** Alte Punkte aufräumen (>24h) */
    suspend fun cleanup() = withContext(Dispatchers.IO) {
        writableDatabase.execSQL(
            "DELETE FROM gps_buffer WHERE recorded_at < strftime('%s','now') - 86400"
        )
    }
}
