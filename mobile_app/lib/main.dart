import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'core/session.dart';
import 'core/theme.dart';
import 'screens/login_screen.dart';
import 'screens/home_shell.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final session = SessionStore();
  await session.load();
  runApp(ChangeNotifierProvider.value(
    value: session,
    child: const SemsApp(),
  ));
}

class SemsApp extends StatelessWidget {
  const SemsApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'SEMS — Smart Electricity',
      debugShowCheckedModeBanner: false,
      theme: semsTheme,
      home: const RootGate(),
    );
  }
}

/// Chooses between login and the authenticated shell.
class RootGate extends StatelessWidget {
  const RootGate({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionStore>();
    if (session.token == null || session.user == null) {
      return const LoginScreen();
    }
    return const HomeShell();
  }
}
