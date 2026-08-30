package com.agency.leadmanager.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.People
import androidx.compose.material.icons.automirrored.filled.PhoneForwarded
import androidx.compose.material.icons.filled.WorkOutline
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.ContextCompat
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.SessionInvalidation
import com.agency.leadmanager.sync.SyncScheduler
import com.agency.leadmanager.ui.forms.FormsScreen
import com.agency.leadmanager.ui.home.HomeScreen
import com.agency.leadmanager.ui.leads.AddLeadScreen
import com.agency.leadmanager.ui.leads.CallbacksScreen
import com.agency.leadmanager.ui.leads.LeadDetailScreen
import com.agency.leadmanager.ui.leads.LeadListScreen
import com.agency.leadmanager.ui.login.LoginScreen
import com.agency.leadmanager.ui.projects.ProjectDetailScreen
import com.agency.leadmanager.ui.projects.ProjectListScreen
import com.agency.leadmanager.ui.settings.SettingsScreen
import com.agency.leadmanager.ui.team.TeamScreen
import com.agency.leadmanager.ui.theme.LeadManagerTheme

class MainActivity : ComponentActivity() {

    override fun onResume() {
        super.onResume()
        AppVisibility.onResumed()
    }

    override fun onPause() {
        AppVisibility.onPaused()
        super.onPause()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val openLeadId = intent?.getLongExtra(EXTRA_OPEN_LEAD_ID, -1L)?.takeIf { it > 0 }

        setContent {
            LeadManagerTheme {
                AppRoot(initialLeadId = openLeadId)
            }
        }
    }

    companion object {
        const val EXTRA_OPEN_LEAD_ID = "open_lead_id"
    }
}

object Routes {
    const val LOGIN = "login"
    const val HOME = "home"
    const val LEADS = "leads"
    const val LEAD_DETAIL = "lead/{leadId}"
    const val ADD_LEAD = "addLead"
    const val CALLBACKS = "callbacks"
    const val PROJECTS = "projects"
    const val PROJECT_DETAIL = "project/{projectId}"
    const val FORMS = "forms"
    const val TEAM = "team"
    const val SETTINGS = "settings"

    fun leadDetail(id: Long) = "lead/$id"
    fun projectDetail(id: Long) = "project/$id"
}

private data class Tab(
    val route: String,
    val label: String,
    val icon: ImageVector,
)

private val tabs = listOf(
    Tab(Routes.HOME, "Home", Icons.Default.Home),
    Tab(Routes.LEADS, "Leads", Icons.Default.People),
    Tab(Routes.CALLBACKS, "Callbacks", Icons.AutoMirrored.Filled.PhoneForwarded),
    Tab(Routes.PROJECTS, "Projects", Icons.Default.WorkOutline),
    Tab(Routes.FORMS, "Forms", Icons.Default.Description),
)

@Composable
private fun AppRoot(initialLeadId: Long?) {
    val context = LocalContext.current
    val locator = remember(context) { ServiceLocator.from(context) }
    val navController = rememberNavController()

    val isLoggedIn by locator.session.isLoggedInFlow.collectAsState(initial = false)

    // Ask for what the app cannot work without, once, on first launch.
    RequestCallPermissions()

    // If the server revoked our token, go back to login.
    LaunchedEffect(Unit) {
        while (true) {
            if (SessionInvalidation.consume()) {
                locator.session.clear()
                navController.navigate(Routes.LOGIN) {
                    popUpTo(0)
                }
            }
            kotlinx.coroutines.delay(1_500)
        }
    }

    LaunchedEffect(isLoggedIn) {
        if (isLoggedIn) {
            SyncScheduler.scheduleRecurring(context)
            SyncScheduler.refreshRemindersNow(context)
            locator.authRepository.refreshLookups()

            initialLeadId?.let { navController.navigate(Routes.leadDetail(it)) }
        }
    }

    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route
    val showBottomBar = isLoggedIn && tabs.any { it.route == currentRoute }

    Scaffold(
        bottomBar = {
            if (showBottomBar) {
                NavigationBar {
                    tabs.forEach { tab ->
                        val selected = backStackEntry?.destination?.hierarchy
                            ?.any { it.route == tab.route } == true

                        NavigationBarItem(
                            selected = selected,
                            onClick = { navController.navigateToTab(tab.route) },
                            icon = { Icon(tab.icon, contentDescription = tab.label) },
                            label = { Text(tab.label) },
                        )
                    }
                }
            }
        }
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = if (isLoggedIn) Routes.HOME else Routes.LOGIN,
            modifier = Modifier.padding(padding),
        ) {
            composable(Routes.LOGIN) {
                LoginScreen(
                    onSignedIn = {
                        navController.navigate(Routes.HOME) { popUpTo(0) }
                    }
                )
            }

            composable(Routes.HOME) {
                HomeScreen(
                    onOpenLead = { navController.navigate(Routes.leadDetail(it)) },
                    onOpenLeads = { navController.navigateToTab(Routes.LEADS) },
                    onOpenCallbacks = { navController.navigateToTab(Routes.CALLBACKS) },
                    onOpenTeam = { navController.navigate(Routes.TEAM) },
                    onOpenSettings = { navController.navigate(Routes.SETTINGS) },
                )
            }

            composable(Routes.LEADS) {
                LeadListScreen(
                    onOpenLead = { navController.navigate(Routes.leadDetail(it)) },
                    onAddLead = { navController.navigate(Routes.ADD_LEAD) },
                )
            }

            composable(Routes.LEAD_DETAIL) { entry ->
                val leadId = entry.arguments?.getString("leadId")?.toLongOrNull() ?: 0L
                LeadDetailScreen(
                    leadId = leadId,
                    onBack = { navController.popBackStack() },
                    onOpenProject = { navController.navigate(Routes.projectDetail(it)) },
                )
            }

            composable(Routes.ADD_LEAD) {
                AddLeadScreen(
                    onSaved = { navController.popBackStack() },
                    onBack = { navController.popBackStack() },
                )
            }

            composable(Routes.CALLBACKS) {
                CallbacksScreen(
                    onOpenLead = { navController.navigate(Routes.leadDetail(it)) },
                )
            }

            composable(Routes.PROJECTS) {
                ProjectListScreen(
                    onOpenProject = { navController.navigate(Routes.projectDetail(it)) },
                )
            }

            composable(Routes.PROJECT_DETAIL) { entry ->
                val projectId = entry.arguments?.getString("projectId")?.toLongOrNull() ?: 0L
                ProjectDetailScreen(
                    projectId = projectId,
                    onBack = { navController.popBackStack() },
                )
            }

            composable(Routes.FORMS) {
                FormsScreen()
            }

            composable(Routes.TEAM) {
                TeamScreen(onBack = { navController.popBackStack() })
            }

            composable(Routes.SETTINGS) {
                SettingsScreen(
                    onBack = { navController.popBackStack() },
                    onSignedOut = {
                        navController.navigate(Routes.LOGIN) { popUpTo(0) }
                    },
                )
            }
        }
    }
}

private fun NavHostController.navigateToTab(route: String) {
    navigate(route) {
        popUpTo(graph.findStartDestination().id) { saveState = true }
        launchSingleTop = true
        restoreState = true
    }
}

/**
 * Asks for the permissions the app genuinely needs on first launch.
 *
 * READ_CALL_LOG is the important one: without it we cannot read how long a call
 * lasted, which is the whole point of the automatic tracking.
 */
@Composable
private fun RequestCallPermissions() {
    val context = LocalContext.current

    val permissions = buildList {
        add(Manifest.permission.READ_PHONE_STATE)
        add(Manifest.permission.READ_CALL_LOG)
        add(Manifest.permission.CALL_PHONE)
        // Optional, but it turns "Is +91 98765 43210 a lead?" into
        // "Is Rajesh a lead?" and makes saving a single tap.
        add(Manifest.permission.READ_CONTACTS)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            add(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    val launcher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { granted ->
        // Granted late? Backfill anything already in the device call log.
        if (granted[Manifest.permission.READ_CALL_LOG] == true) {
            SyncScheduler.catchUpNow(context)
        }
    }

    LaunchedEffect(Unit) {
        val missing = permissions.filter {
            ContextCompat.checkSelfPermission(context, it) != PackageManager.PERMISSION_GRANTED
        }

        if (missing.isNotEmpty()) {
            launcher.launch(missing.toTypedArray())
        }
    }
}

/** Opens the phone dialler with the number pre-filled. */
fun dial(context: android.content.Context, phone: String) {
    val intent = Intent(Intent.ACTION_CALL, Uri.parse("tel:${phone.filter { it.isDigit() || it == '+' }}"))

    val canCallDirectly = ContextCompat.checkSelfPermission(
        context, Manifest.permission.CALL_PHONE
    ) == PackageManager.PERMISSION_GRANTED

    val toStart = if (canCallDirectly) {
        intent
    } else {
        Intent(Intent.ACTION_DIAL, intent.data)
    }

    try {
        context.startActivity(toStart)
    } catch (e: Exception) {
        // No dialler on the device (shouldn't happen on a phone).
    }
}

/** Opens WhatsApp for this number. */
fun openWhatsApp(context: android.content.Context, phone: String) {
    val number = com.agency.leadmanager.util.PhoneUtils.toWhatsAppNumber(phone) ?: return

    try {
        context.startActivity(
            Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/$number"))
        )
    } catch (e: Exception) {
        // WhatsApp not installed.
    }
}
