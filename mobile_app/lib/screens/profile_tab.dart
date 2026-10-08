import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/common.dart';

class ProfileTab extends StatefulWidget {
  const ProfileTab({super.key});
  @override
  State<ProfileTab> createState() => _ProfileTabState();
}

class _ProfileTabState extends State<ProfileTab> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _currentPass = TextEditingController();
  final _newPass = TextEditingController();
  bool _busyProfile = false;
  bool _busyPass = false;
  String? _profileMsg;
  String? _passMsg;
  bool _passError = false;

  @override
  void initState() {
    super.initState();
    final session = context.read<SessionStore>();
    _name.text = session.userName ?? '';
    _phone.text = session.user?['phone'] ?? '';
  }

  Future<void> _saveProfile() async {
    setState(() { _busyProfile = true; _profileMsg = null; });
    try {
      await SessionStore.api.patch('/auth/me', {'full_name': _name.text.trim(), 'phone': _phone.text.trim()});
      await context.read<SessionStore>().refreshMe();
      setState(() => _profileMsg = 'Profile updated.');
    } on ApiException catch (e) {
      setState(() => _profileMsg = e.detailed);
    } finally {
      if (mounted) setState(() => _busyProfile = false);
    }
  }

  Future<void> _changePassword() async {
    setState(() { _busyPass = true; _passMsg = null; _passError = false; });
    try {
      await SessionStore.api.patch('/auth/me', {'current_password': _currentPass.text, 'new_password': _newPass.text});
      _currentPass.clear();
      _newPass.clear();
      setState(() => _passMsg = 'Password changed.');
    } on ApiException catch (e) {
      setState(() { _passMsg = e.detailed; _passError = true; });
    } finally {
      if (mounted) setState(() => _busyPass = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionStore>();
    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              CircleAvatar(radius: 26, backgroundColor: kNavy, child: Text((session.userName ?? 'U').substring(0, 1).toUpperCase(), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 20))),
              const SizedBox(width: 14),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(session.userName ?? '', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                Text(session.email ?? '', style: const TextStyle(color: kMuted, fontSize: 12.5)),
                const SizedBox(height: 4),
                const StatusChip('active', label: 'consumer account'),
              ])),
            ]),
          ),
        ),
        const SectionTitle('Edit profile'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(controller: _name, decoration: const InputDecoration(labelText: 'Full name')),
              const SizedBox(height: 12),
              TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Phone (+255…)', hintText: '+2557XXXXXXXX')),
              if (_profileMsg != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_profileMsg!, style: const TextStyle(fontSize: 12.5, color: kGreen))),
              const SizedBox(height: 12),
              ElevatedButton(onPressed: _busyProfile ? null : _saveProfile, child: const Text('Save profile')),
            ]),
          ),
        ),
        const SectionTitle('Change password'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(controller: _currentPass, obscureText: true, decoration: const InputDecoration(labelText: 'Current password')),
              const SizedBox(height: 12),
              TextField(controller: _newPass, obscureText: true, decoration: const InputDecoration(labelText: 'New password', helperText: 'Min 8 characters, letters and numbers')),
              if (_passMsg != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_passMsg!, style: TextStyle(fontSize: 12.5, color: _passError ? kRed : kGreen))),
              const SizedBox(height: 12),
              OutlinedButton(onPressed: _busyPass ? null : _changePassword, child: const Text('Change password')),
            ]),
          ),
        ),
        const SectionTitle('About'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Text('SEMS consumer app · academic prototype', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 6),
              const Text(
                'This app manages electricity records and simulated meter data for research/education. '
                'It is NOT an official TANESCO application and cannot load tokens onto physical LUKU meters.',
                style: TextStyle(fontSize: 12, color: kMuted),
              ),
              const SizedBox(height: 14),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: kRed, side: BorderSide(color: kRed.withOpacity(.4))),
                onPressed: () => showDialog<void>(
                  context: context,
                  builder: (ctx) => AlertDialog(
                    title: const Text('Log out?'),
                    content: const Text('You will need to sign in again.'),
                    actions: [
                      TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
                      TextButton(onPressed: () { Navigator.pop(ctx); session.logout(); }, child: const Text('Log out', style: TextStyle(color: kRed))),
                    ],
                  ),
                ),
                icon: const Icon(Icons.logout, size: 18),
                label: const Text('Log out'),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}
