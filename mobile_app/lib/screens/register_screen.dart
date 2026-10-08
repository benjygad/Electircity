import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});
  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _busy = true; _error = null; });
    try {
      await context.read<SessionStore>().register({
        'full_name': _name.text.trim(),
        'email': _email.text.trim(),
        'phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        'password': _password.text,
      });
    } on ApiException catch (e) {
      setState(() => _error = e.detailed);
    } catch (_) {
      setState(() => _error = 'Could not reach the SEMS server.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Create account')),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(padding: const EdgeInsets.all(20), children: [
            const Text('Join SEMS', style: TextStyle(fontSize: 21, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            const Text('Register to monitor your electricity usage and manage your prepaid records.', style: TextStyle(color: kMuted, fontSize: 13)),
            const SizedBox(height: 18),
            if (_error != null)
              Container(
                margin: const EdgeInsets.only(bottom: 14),
                padding: const EdgeInsets.all(11),
                decoration: BoxDecoration(color: kRed.withOpacity(.08), borderRadius: BorderRadius.circular(10)),
                child: Text(_error!, style: const TextStyle(color: kRed, fontSize: 12.5)),
              ),
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(labelText: 'Full name'),
              validator: (v) => (v == null || v.trim().length < 2) ? 'Enter your full name' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(labelText: 'Email'),
              validator: (v) {
                final e = v?.trim() ?? '';
                return RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(e) ? null : 'Enter a valid email';
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Phone (optional)', hintText: '+2557XXXXXXXX'),
              validator: (v) {
                if (v == null || v.trim().isEmpty) return null;
                return RegExp(r'^\+?255\d{9}$').hasMatch(v.replaceAll(RegExp(r'[\s-]'), '')) ? null : 'Use Tanzanian format, e.g. +255712345678';
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _password,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Password', helperText: 'Min 8 characters, with letters and numbers'),
              validator: (v) {
                if (v == null || v.length < 8) return 'At least 8 characters';
                if (!RegExp(r'[A-Za-z]').hasMatch(v) || !RegExp(r'\d').hasMatch(v)) return 'Must contain letters and numbers';
                return null;
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _confirm,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Confirm password'),
              validator: (v) => v == _password.text ? null : 'Passwords do not match',
            ),
            const SizedBox(height: 22),
            ElevatedButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                  : const Text('Create account'),
            ),
          ]),
        ),
      ),
    );
  }
}
