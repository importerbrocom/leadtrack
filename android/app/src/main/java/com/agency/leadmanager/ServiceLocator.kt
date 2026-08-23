package com.agency.leadmanager

import android.content.Context
import com.agency.leadmanager.data.local.AppDatabase
import com.agency.leadmanager.data.prefs.SessionStore
import com.agency.leadmanager.data.remote.ApiClient
import com.agency.leadmanager.data.repo.AuthRepository
import com.agency.leadmanager.data.repo.CallRepository
import com.agency.leadmanager.data.repo.DashboardRepository
import com.agency.leadmanager.data.repo.DocumentRepository
import com.agency.leadmanager.data.repo.LeadRepository
import com.agency.leadmanager.data.repo.ProjectRepository
import com.agency.leadmanager.data.repo.TeamRepository

/**
 * Hand-rolled dependency container.
 *
 * A dependency-injection framework would add build complexity (and an
 * annotation processor) for an app with one graph and no variants. Everything
 * here is created once and reused; broadcast receivers, services and workers
 * all reach it through [from].
 */
class ServiceLocator private constructor(context: Context) {

    private val appContext = context.applicationContext

    val session: SessionStore by lazy { SessionStore(appContext) }

    val database: AppDatabase by lazy { AppDatabase.get(appContext) }

    val apiClient: ApiClient by lazy { ApiClient(session) }

    val authRepository: AuthRepository by lazy {
        AuthRepository(apiClient, session, database)
    }

    val leadRepository: LeadRepository by lazy {
        LeadRepository(apiClient, database)
    }

    val callRepository: CallRepository by lazy {
        CallRepository(apiClient, database)
    }

    val projectRepository: ProjectRepository by lazy {
        ProjectRepository(apiClient)
    }

    val documentRepository: DocumentRepository by lazy {
        DocumentRepository(apiClient, appContext)
    }

    val dashboardRepository: DashboardRepository by lazy {
        DashboardRepository(apiClient)
    }

    val teamRepository: TeamRepository by lazy {
        TeamRepository(apiClient)
    }

    companion object {
        @Volatile
        private var instance: ServiceLocator? = null

        fun from(context: Context): ServiceLocator =
            instance ?: synchronized(this) {
                instance ?: ServiceLocator(context).also { instance = it }
            }
    }
}
