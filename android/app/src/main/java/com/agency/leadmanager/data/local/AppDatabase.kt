package com.agency.leadmanager.data.local

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

@Database(
    entities = [
        PendingCallEntity::class,
        CachedLeadEntity::class,
        PendingLeadEntity::class,
        PendingStatusUpdateEntity::class,
    ],
    version = 1,
    exportSchema = false,
)
abstract class AppDatabase : RoomDatabase() {

    abstract fun pendingCallDao(): PendingCallDao
    abstract fun cachedLeadDao(): CachedLeadDao
    abstract fun pendingLeadDao(): PendingLeadDao
    abstract fun pendingStatusUpdateDao(): PendingStatusUpdateDao

    companion object {
        @Volatile
        private var instance: AppDatabase? = null

        fun get(context: Context): AppDatabase =
            instance ?: synchronized(this) {
                instance ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "lead_manager.db"
                )
                    // The local database is a cache plus an outbox; if a future
                    // version changes shape, rebuilding is safe as long as the
                    // outbox has drained. Kept explicit so it is a decision,
                    // not an accident.
                    .fallbackToDestructiveMigration()
                    .build()
                    .also { instance = it }
            }
    }
}
