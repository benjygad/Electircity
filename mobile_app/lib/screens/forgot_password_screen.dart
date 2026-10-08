import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';

/// Two-step flow: request a reset code, then set a new password.
/// In this academic prototype the code is shown by the server in development
/// mode (a real deployment would send it via email/SMS).
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});
  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  int _step = 0;
  bool _busy = false;
  String? _error;
  String? _info;
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();

  Future<void> _requestCode() async {
    setState(() { _busy = true; _error = null; });
    try {
      final data = await SessionStore.api.post('/auth/forgot-password', {'email': _email.text.trim()});
      setState(() {
        _step = 1;
        _info = data['message'];
        if (data['dev_reset_code'] != null) {
          _info = 'Development mode: your reset code is ${data['dev_reset_code']}';
        }
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reset() async {
    setState(() { _busy = true; _error = null; });
    try {
      await SessionStore.api.post('/auth/reset-password', {
        'email': _email.text.trim(),
        'code': _code.text.trim(),
        'new_password': _password.text,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Password updated. Please sign in.')),
        );
        Navigator.pop(context);
      }
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Reset password')),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        if (_error != null)
          Container(margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.all(11),
            decoration: BoxDecoration(color: kRed.withOpacity(.08), borderRadius: BorderRadius.circular(10)),
            child: Text(_error!, style: const TextStyle(color: kRed, fontSize: 12.5))),
        if (_info != null)
          Container(margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.all(11),
            decoration: BoxDecoration(color: kTeal.withOpacity(.1), borderRadius: BorderRadius.circular(10)),
            child: Text(_info!, style: const TextStyle(color: Color(0xFF0B6470), fontSize: 12.5))),
        TextField(controller: _email, enabled: _step == 0, keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(labelText: 'Email')),
        if (_step == 1) ...[
          const SizedBox(height: 12),
          TextField(controller: _code, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Reset code')),
          const SizedBox(height: 12),
          TextField(controller: _password, obscureText: true,
            decoration: const InputDecoration(labelText: 'New password', helperText: 'Min 8 characters, letters and numbers')),
        ],
        const SizedBox(height: 22),
        ElevatedButton(
          onPressed: _busy ? null : (_step == 0 ? _requestCode : _reset),
          child: _busy
              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
              : Text(_step == 0 ? 'Request reset code' : 'Set new password'),
        ),
      ]),
    );
  }
}
