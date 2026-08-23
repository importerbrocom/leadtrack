package com.agency.leadmanager.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewmodel.compose.viewModel
import com.agency.leadmanager.ServiceLocator

/**
 * Creates a ViewModel with the ServiceLocator injected, without pulling in a
 * DI framework.
 */
@Composable
inline fun <reified VM : ViewModel> appViewModel(
    key: String? = null,
    crossinline create: (ServiceLocator) -> VM,
): VM {
    val context = LocalContext.current
    val locator = remember(context) { ServiceLocator.from(context) }

    val factory = remember(locator, key) {
        object : ViewModelProvider.Factory {
            override fun <T : ViewModel> create(modelClass: Class<T>): T {
                @Suppress("UNCHECKED_CAST")
                return create(locator) as T
            }
        }
    }

    return viewModel(key = key, factory = factory)
}

/** One-shot message for snackbars. */
data class UiMessage(val text: String, val isError: Boolean = false)
