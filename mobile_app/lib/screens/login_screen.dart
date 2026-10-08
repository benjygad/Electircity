import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import 'forgot_password_screen.dart';
import 'register_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  Future<void> _login() async {
    setState(() { _busy = true; _error = null; });
    try {
      await context.read<SessionStore>().login(_email.text.trim(), _password.text);
    } on ApiException catch (e) {
      setState(() => _error = e.detailed);
    } catch (_) {
      setState(() => _error = 'Could not reach the SEMS server. Check your connection and API URL.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: kNavy,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Container(
              constraints: const BoxConstraints(maxWidth: 420),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
              padding: const EdgeInsets.all(26),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Container(
                  width: 58, height: 58,
                  decoration: BoxDecoration(color: kTeal, borderRadius: BorderRadius.circular(16)),
                  child: const Icon(Icons.bolt, color: Colors.white, size: 32),
                ),
                const SizedBox(height: 16),
                const Text('Welcome to SEMS', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4),
                const Text('Smart Electricity Management — Tanzania', style: TextStyle(color: kMuted, fontSize: 13)),
                const SizedBox(height: 20),
                if (_error != null)
                  Container(
                    margin: const EdgeInsets.only(bottom: 14),
                    padding: const EdgeInsets.all(11),
                    decoration: BoxDecoration(color: kRed.withOpacity(.08), borderRadius: BorderRadius.circular(10)),
                    child: Text(_error!, style: const TextStyle(color: kRed, fontSize: 12.5)),
                  ),
                TextField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'Email', prefixIcon: Icon(Icons.email_outlined)),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _password,
                  obscureText: true,
                  onSubmitted: (_) => _login(),
                  decoration: const InputDecoration(labelText: 'Password', prefixIcon: Icon(Icons.lock_outline)),
                ),
                Align(
                  alignment: Alignment.centerRight,
                  child: TextButton(
                    onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ForgotPasswordScreen())),
                    child: const Text('Forgot password?', style: TextStyle(color: kTeal, fontWeight: FontWeight.w600)),
                  ),
                ),
                const SizedBox(height: 6),
                ElevatedButton(
                  onPressed: _busy ? null : _login,
                  child: _busy
                      ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                      : const Text('Sign in'),
                ),
                const SizedBox(height: 12),
                OutlinedButton(
                  onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const RegisterScreen())),
                  child: const Text('Create an account'),
                ),
                const SizedBox(height: 14),
                const Text('Academic prototype — meter data is simulated.', textAlign: TextAlign.center, style: TextStyle(fontSize: 11, color: kMuted)),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
