package ch.papiersammlung.app

import android.content.Intent
import android.os.Bundle
import android.widget.*
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity

class SettingsActivity : AppCompatActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_settings)

        val tvUser    = findViewById<TextView>(R.id.tv_current_user)
        val tvServer  = findViewById<TextView>(R.id.tv_current_server)
        val btnLogout = findViewById<Button>(R.id.btn_logout)

        tvUser.text   = "Benutzer: ${AppPrefs.username}"
        tvServer.text = "Server: ${AppPrefs.serverUrl}"

        btnLogout.setOnClickListener {
            AlertDialog.Builder(this)
                .setTitle("Abmelden")
                .setMessage("Möchtest du dich wirklich abmelden?")
                .setPositiveButton("Abmelden") { _, _ ->
                    AppPrefs.logout()
                    startActivity(Intent(this, LoginActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
                    finish()
                }
                .setNegativeButton("Abbrechen", null)
                .show()
        }
    }
}
