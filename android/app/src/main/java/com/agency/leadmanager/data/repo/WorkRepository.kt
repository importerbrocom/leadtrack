package com.agency.leadmanager.data.repo

import android.content.Context
import android.net.Uri
import android.webkit.MimeTypeMap
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.apiCall
import com.agency.leadmanager.data.remote.ApiClient
import com.agency.leadmanager.data.remote.dto.*
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.io.FileOutputStream

/** Projects: converted leads moving through visa/medical/deployment. */
class ProjectRepository(private val client: ApiClient) {

    suspend fun projects(status: String? = null, search: String? = null, page: Int = 1): ApiResult<List<ProjectDto>> =
        apiCall { client.api.projects(status = status, search = search, page = page) }

    suspend fun project(id: Long): ApiResult<ProjectDto> =
        apiCall { client.api.project(id) }

    suspend fun updateStatus(id: Long, status: String, remarks: String?): ApiResult<ProjectDto> =
        apiCall { client.api.updateProjectStatus(id, StatusRequest(status = status, remarks = remarks)) }
}

/** Dashboard, lookups and notifications. */
class DashboardRepository(private val client: ApiClient) {

    suspend fun dashboard(): ApiResult<DashboardDto> = apiCall { client.api.dashboard() }

    suspend fun lookups(): ApiResult<LookupsDto> = apiCall { client.api.lookups() }

    suspend fun notifications(page: Int = 1): ApiResult<List<NotificationDto>> =
        apiCall { client.api.notifications(page = page) }

    suspend fun markNotificationsRead(): ApiResult<Unit> =
        apiCall { client.api.markNotificationsRead() }
}

/** Partners managing their own telecallers. */
class TeamRepository(private val client: ApiClient) {

    suspend fun team(search: String? = null): ApiResult<List<UserDto>> =
        apiCall { client.api.users(search = search) }

    suspend fun assignable(): ApiResult<List<AssignableUserDto>> =
        apiCall { client.api.assignableUsers() }

    suspend fun addTelecaller(
        name: String,
        phone: String,
        password: String,
        email: String? = null,
        city: String? = null,
    ): ApiResult<UserDto> = apiCall {
        client.api.createUser(
            CreateUserRequest(
                role = "telecaller",
                name = name.trim(),
                phone = phone.trim(),
                password = password,
                email = email?.trim()?.takeIf { it.isNotBlank() },
                city = city?.trim()?.takeIf { it.isNotBlank() },
            )
        )
    }

    suspend fun setActive(userId: Int, active: Boolean): ApiResult<UserDto> =
        apiCall { client.api.updateUser(userId, UpdateUserRequest(isActive = active)) }

    suspend fun resetPassword(userId: Int, password: String): ApiResult<UserDto> =
        apiCall { client.api.updateUser(userId, UpdateUserRequest(password = password)) }
}

/**
 * Blank forms (download) and candidate paperwork (upload).
 */
class DocumentRepository(
    private val client: ApiClient,
    private val context: Context,
) {

    suspend fun formTemplates(): ApiResult<List<FormTemplateDto>> =
        apiCall { client.api.formTemplates() }

    suspend fun documentTypes(): ApiResult<List<DocumentTypeDto>> =
        apiCall { client.api.documentTypes() }

    suspend fun documents(projectId: Long? = null, leadId: Long? = null): ApiResult<List<DocumentDto>> =
        apiCall { client.api.documents(projectId = projectId, leadId = leadId) }

    /**
     * Download a blank form into app storage and hand back the file so the UI
     * can open it with a PDF viewer.
     */
    suspend fun downloadTemplate(template: FormTemplateDto): ApiResult<File> {
        return try {
            val response = client.api.downloadFormTemplate(template.id)

            if (!response.isSuccessful) {
                return ApiResult.Failure(
                    if (response.code() == 404) "This form is no longer available"
                    else "Download failed (${response.code()})",
                    response.code()
                )
            }

            val body = response.body()
                ?: return ApiResult.Failure("The server sent an empty file")

            val dir = File(context.getExternalFilesDir(null), "downloads").apply { mkdirs() }
            val target = File(dir, safeFileName(template.fileName.ifBlank { "form-${template.id}" }))

            body.byteStream().use { input ->
                FileOutputStream(target).use { output -> input.copyTo(output) }
            }

            ApiResult.Success(target)
        } catch (e: Exception) {
            ApiResult.Failure(e.message ?: "Could not download the form")
        }
    }

    suspend fun downloadDocument(document: DocumentDto): ApiResult<File> {
        return try {
            val response = client.api.downloadDocument(document.id)

            if (!response.isSuccessful) {
                return ApiResult.Failure("Download failed (${response.code()})", response.code())
            }

            val body = response.body() ?: return ApiResult.Failure("The server sent an empty file")

            val dir = File(context.getExternalFilesDir(null), "downloads").apply { mkdirs() }
            val target = File(dir, safeFileName(document.fileName.ifBlank { "document-${document.id}" }))

            body.byteStream().use { input ->
                FileOutputStream(target).use { output -> input.copyTo(output) }
            }

            ApiResult.Success(target)
        } catch (e: Exception) {
            ApiResult.Failure(e.message ?: "Could not download the document")
        }
    }

    /**
     * Upload a filled form or scan picked from the gallery / file picker.
     * The content: Uri is copied into the cache first, because OkHttp needs a
     * real file to stream and to know the length.
     */
    suspend fun uploadDocument(
        uri: Uri,
        displayName: String?,
        projectId: Long? = null,
        leadId: Long? = null,
        documentTypeId: Int? = null,
        title: String? = null,
        documentNumber: String? = null,
        expiryDate: String? = null,
    ): ApiResult<DocumentDto> {
        val temp = try {
            copyToCache(uri, displayName)
        } catch (e: Exception) {
            return ApiResult.Failure("Could not read the selected file")
        }

        if (temp.length() == 0L) {
            temp.delete()
            return ApiResult.Failure("That file is empty")
        }

        val mime = context.contentResolver.getType(uri)
            ?: guessMime(temp.name)
            ?: "application/octet-stream"

        val part = MultipartBody.Part.createFormData(
            "file",
            temp.name,
            temp.asRequestBody(mime.toMediaTypeOrNull())
        )

        val result = apiCall {
            client.api.uploadDocument(
                file = part,
                projectId = projectId?.toString()?.toPlainPart(),
                leadId = leadId?.toString()?.toPlainPart(),
                documentTypeId = documentTypeId?.toString()?.toPlainPart(),
                title = title?.takeIf { it.isNotBlank() }?.toPlainPart(),
                documentNumber = documentNumber?.takeIf { it.isNotBlank() }?.toPlainPart(),
                expiryDate = expiryDate?.takeIf { it.isNotBlank() }?.toPlainPart(),
            )
        }

        temp.delete()
        return result
    }

    suspend fun deleteDocument(id: Long): ApiResult<Unit> =
        apiCall { client.api.deleteDocument(id) }

    // ------------------------------------------------------------ helpers

    private fun copyToCache(uri: Uri, displayName: String?): File {
        val name = safeFileName(displayName ?: "upload-${System.currentTimeMillis()}")
        val target = File(context.cacheDir, name)

        context.contentResolver.openInputStream(uri)?.use { input ->
            FileOutputStream(target).use { output -> input.copyTo(output) }
        } ?: throw IllegalStateException("Cannot open $uri")

        return target
    }

    private fun safeFileName(raw: String): String {
        val cleaned = raw.substringAfterLast('/').replace(Regex("[^A-Za-z0-9._\\-]"), "_")
        return cleaned.take(120).ifBlank { "file" }
    }

    private fun guessMime(fileName: String): String? {
        val ext = fileName.substringAfterLast('.', "").lowercase()
        return MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext)
    }
}

private fun String.toPlainPart(): RequestBody =
    this.toRequestBody("text/plain".toMediaTypeOrNull())
