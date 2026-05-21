package ch.papiersammlung.app

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.*
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch

class LoginActivity : AppCompatActivity() {

    private lateinit var etServer: EditText
    private lateinit var etUsername: EditText
    private lateinit var etPassword: EditText
    private lateinit var btnLogin: Button
    private lateinit var tvError: TextView
    private lateinit var progressBar: ProgressBar
    private lateinit var tvServerLabel: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_login)

        etServer     = findViewById(R.id.et_server)
        etUsername   = findViewById(R.id.et_username)
        etPassword   = findViewById(R.id.et_password)
        btnLogin     = findViewById(R.id.btn_login)
        tvError      = findViewById(R.id.tv_error)
        progressBar  = findViewById(R.id.progress_bar)
        tvServerLabel = findViewById(R.id.tv_server_label)

        // Server-URL vorausfüllen (Default oder gespeichert)
        etServer.setText(AppPrefs.serverUrl)
        etUsername.setText(AppPrefs.username)

        // Server-URL-Feld ausblenden und nur bei Klick auf Label einblenden
        etServer.visibility = View.GONE
        tvServerLabel.text = "🌐 ${AppPrefs.serverUrl}"
        tvServerLabel.setOnClickListener {
            etServer.visibility = if (etServer.visibility == View.VISIBLE) {
                tvServerLabel.text = "🌐 ${etServer.text}"
                View.GONE
            } else {
                View.VISIBLE
            }
        }

        btnLogin.setOnClickListener { doLogin() }
        etPassword.setOnEditorActionListener { _, _, _ -> doLogin(); true }
    }

    private fun doLogin() {
        // Server-URL aus Feld falls sichtbar, sonst gespeicherten Wert
        val server = if (etServer.visibility == View.VISIBLE)
            etServer.text.toString().trim().trimEnd('/')
        else
            AppPrefs.serverUrl

        val username = etUsername.text.toString().trim()
        val password = etPassword.text.toString()

        if (username.isEmpty() || password.isEmpty()) {
            showError("Benutzername und Passwort eingeben")
            return
        }
        if (server.isEmpty()) {
            showError("Server-URL fehlt")
            etServer.visibility = View.VISIBLE
            return
        }

        tvError.visibility  = View.GONE
        progressBar.visibility = View.VISIBLE
        btnLogin.isEnabled  = false

        AppPrefs.serverUrl = server
        AppPrefs.username  = username
        tvServerLabel.text = "🌐 $server"

        lifecycleScope.launch {
            val result = ApiClient.login(username, password)
            runOnUiThread {
                progressBar.visibility = View.GONE
                btnLogin.isEnabled = true

                when {
                    result == null ->
                        showError("❌ Keine Verbindung zu:\n$server\n\nServer erreichbar? Neue api.php hochgeladen?")
                    result.has("error") ->
                        showError("❌ ${result.optString("error", "Login fehlgeschlagen")}\n\n" +
                            "Falls 'Nicht eingeloggt': neue api.php auf den Server hochladen!")
                    result.has("token") -> {
                        AppPrefs.bearerToken = result.optString("token")
                        AppPrefs.username    = result.optString("username", username)
                        AppPrefs.userRole    = result.optString("role", "user")
                        startActivity(Intent(this@LoginActivity, MainActivity::class.java))
                        finish()
                    }
                    else ->
                        showError("Unbekannte Server-Antwort:\n${result}")
                }
            }
        }
    }

    private fun showError(msg: String) {
        tvError.text = msg
        tvError.visibility = View.VISIBLE
    }
}
