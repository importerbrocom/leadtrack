package com.agency.leadmanager.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

val BrandBlue = Color(0xFF0D4F8B)
val BrandBlueDark = Color(0xFF093A68)
val BrandBlueLight = Color(0xFFD6E6F5)

val StatusNew = Color(0xFF6C757D)
val StatusContacted = Color(0xFF0DA2C0)
val StatusInterested = Color(0xFF0D6EFD)
val StatusFollowUp = Color(0xFFE0A800)
val StatusConverted = Color(0xFF198754)
val StatusLost = Color(0xFFDC3545)
val StatusDark = Color(0xFF343A40)

val PriorityHigh = Color(0xFFDC3545)

private val Light = lightColorScheme(
    primary = BrandBlue,
    onPrimary = Color.White,
    primaryContainer = BrandBlueLight,
    onPrimaryContainer = BrandBlueDark,
    secondary = StatusContacted,
    onSecondary = Color.White,
    background = Color(0xFFF6F8FA),
    onBackground = Color(0xFF1B1F24),
    surface = Color.White,
    onSurface = Color(0xFF1B1F24),
    surfaceVariant = Color(0xFFEEF2F6),
    onSurfaceVariant = Color(0xFF505A64),
    error = StatusLost,
    onError = Color.White,
)

private val Dark = darkColorScheme(
    primary = Color(0xFF7FB3E0),
    onPrimary = Color(0xFF03253F),
    primaryContainer = BrandBlueDark,
    onPrimaryContainer = Color.White,
    background = Color(0xFF11151A),
    onBackground = Color(0xFFE6EAEF),
    surface = Color(0xFF1A1F26),
    onSurface = Color(0xFFE6EAEF),
    surfaceVariant = Color(0xFF262D36),
    onSurfaceVariant = Color(0xFFB3BCC6),
    error = Color(0xFFFF6B7A),
)

/** Colour for a lead or project status chip. */
fun statusColor(status: String): Color = when (status) {
    "new" -> StatusNew
    "contacted" -> StatusContacted
    "interested", "selected", "visa_approved", "ticket_booked" -> StatusInterested
    "follow_up", "documents_pending", "medical_pending", "pcc_pending", "visa_processing", "pending" ->
        StatusFollowUp
    "converted", "deployed", "completed", "verified", "documents_verified", "medical_cleared" ->
        StatusConverted
    "lost", "invalid", "dnd", "cancelled", "rejected" -> StatusLost
    "not_interested", "on_hold" -> StatusDark
    else -> StatusNew
}

@Composable
fun LeadManagerTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = if (darkTheme) Dark else Light,
        content = content
    )
}
