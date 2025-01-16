<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" 
class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} - @yield('title', 'Welcome')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jquery-images-compare@0.2.5/src/assets/css/images-compare.min.css">
    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* Reset & Base Styles */
        :root {
            --primary-color: #5865F2;
            --secondary-color: #23272A;
            --accent-color: #4752C4;
            --text-color: #4F5660;
            --light-color: #FFFFFF;
            --background-color: #F6F6F6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        main {
            flex: 1;
        }

        /* Enhanced Navbar */
        .guest-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 1rem 2rem;
            background: var(--light-color);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .navbar-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-link {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 1rem;
            transition: color 0.3s ease;
        }

        .nav-link:hover {
            color: var(--primary-color);
        }

        /* Profile Dropdown */
        .profile-dropdown {
            position: relative;
            display: inline-block;
        }

        .profile-button {
            background: none;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            color: var(--secondary-color);
            font-size: 1rem;
            font-weight: 500;
        }

        .profile-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            min-width: 200px;
            background-color: var(--light-color);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            z-index: 1000;
        }

        .profile-dropdown.active .profile-dropdown-content {
            display: block;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: var(--secondary-color);
            text-decoration: none;
            transition: background-color 0.3s ease;
            border: none;
            width: 100%;
            text-align: left;
            font-size: 0.875rem;
            cursor: pointer;
            background: none;
        }

        .dropdown-item:hover {
            background-color: var(--background-color);
            color: var(--primary-color);
        }

        /* Language Switcher */
        .language-switcher {
            position: relative;
            margin-left: 1rem;
        }

        .language-button {
            background: none;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            color: var(--secondary-color);
        }

        .language-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--light-color);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: none;
            min-width: 150px;
            z-index: 1000;
        }

        .language-dropdown.active {
            display: block;
        }



        .language-option {
    display: block;
    padding: 0.75rem 1rem;
    cursor: pointer;
    transition: background-color 0.3s ease;
    text-decoration: none;
    color: var(--secondary-color);
}

.language-option:hover {
    background-color: var(--background-color);
    color: var(--primary-color);
}

.language-option.active {
    background-color: var(--background-color);
    color: var(--primary-color);
}

        /* Footer */
        .footer {
            background: var(--secondary-color);
            padding: 4rem 2rem;
            color: var(--light-color);
            margin-top: auto;
        }

        .footer-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr repeat(4, 1fr);
            gap: 2rem;
        }

        .footer-brand h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }

        .social-links {
            display: flex;
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .social-links a {
            color: var(--light-color);
            font-size: 1.25rem;
            transition: color 0.3s ease;
        }

        .social-links a:hover {
            color: var(--primary-color);
        }

        .footer-column h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.125rem;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: var(--light-color);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--primary-color);
        }

        /* Dark Mode Styles */
        .dark body {
            background-color: var(--secondary-color);
            color: var(--light-color);
        }

        .dark .guest-navbar {
            background-color: #1a1b1e;
        }

        .dark .nav-link,
        .dark .profile-button,
        .dark .language-button {
            color: var(--light-color);
        }

        .dark .profile-dropdown-content,
        .dark .language-dropdown {
            background-color: #1a1b1e;
        }

        .dark .dropdown-item:hover,
        .dark .language-option:hover {
            background-color: #2d2f34;
        }
        .mobile-menu-button {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
    font-size: 1.5rem;
    color: var(--secondary-color);
}

        /* Mobile Styles */
        @media (max-width: 768px) {
            .mobile-menu-button {
                display: block;
            }

            .nav-links {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: var(--light-color);
                padding: 1rem;
                flex-direction: column;
                align-items: flex-start;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }

            .nav-links.active {
                display: flex;
            }

            .footer-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @stack('styles')
</head>
<body class="antialiased">
    @auth
        <nav class="guest-navbar">
            <div class="navbar-container">
                <a href="/" class="logo">{{ config('app.name') }}</a>
                
                <button class="mobile-menu-button">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="nav-links">
                    <a href="{{ route('dashboard') }}" class="nav-link">{{ __('messages.Dashboard') }}</a>
                    @if(auth()->user()->hasRole('admin'))
                        <a href="{{ route('admin') }}" class="nav-link">{{ __('messages.Admin') }}</a>
                    @endif
                    
                    <div class="profile-dropdown">
                        <button class="profile-button nav-link">
                            {{ Auth::user()->name }}
                            <i class="fas fa-chevron-down ml-2"></i>
                        </button>
                        <div class="profile-dropdown-content">
                            <a href="{{ route('profile.edit') }}" class="dropdown-item">
                                <i class="fas fa-user mr-2"></i> {{ __('messages.Profile') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item">
                                    <i class="fas fa-sign-out-alt mr-2"></i> {{ __('messages.Log Out') }}
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="language-switcher">
    <button class="language-button">
        <i class="fas fa-globe"></i>
        <span>{{ strtoupper(Session::get('locale', 'en')) }}</span>
    </button>
    <div class="language-dropdown">
        @php($languages = ['en' => 'English', 'fr' => 'Français', 'ar' => 'العربية'])
        @foreach($languages as $code => $name)
            <a href="{{ route('change.lang', ['lang' => $code]) }}" 
               class="language-option {{ Session::get('locale') === $code ? 'active' : '' }}">
                {{ $name }}
            </a>
        @endforeach
    </div>
</div>


                </div>
            </div>
        </nav>

        @if(request()->routeIs('dashboard'))
            @if(isset($header))
                <header class="bg-white dark:bg-gray-800 shadow mt-16">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif
            <main class="py-12">
                {{ $slot ?? '' }}
                @yield('content')
            </main>
        @else
            <main class="mt-16">
                @yield('content')
            </main>
        @endif
    @else
        <nav class="guest-navbar">
            <div class="navbar-container">
                <a href="/" class="logo">{{ config('app.name') }}</a>
                
                <button class="mobile-menu-button">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="nav-links">
                    <a href="#features" class="nav-link">{{ __('messages.Features') }}</a>
                    <a href="#download" class="nav-link">{{ __('messages.Download') }}</a>
                    <a href="#pricing" class="nav-link">{{ __('messages.Pricing') }}</a>
                    <a href="#" class="nav-link">{{ __('messages.Support') }}</a>
                    
                    <a href="{{ route('login') }}" class="nav-link">{{ __('messages.Login') }}</a>
                    <a href="{{ route('register') }}" class="nav-link">{{ __('messages.Sign Up') }}</a>




                    <div class="language-switcher">
    <button class="language-button">
        <i class="fas fa-globe"></i>
        <span>{{ strtoupper(Session::get('locale', 'en')) }}</span>
    </button>
    <div class="language-dropdown">
        @php($languages = ['en' => 'English', 'fr' => 'Français', 'ar' => 'العربية'])
        @foreach($languages as $code => $name)
            <a href="{{ route('change.lang', ['lang' => $code]) }}" 
               class="language-option {{ Session::get('locale') === $code ? 'active' : '' }}">
                {{ $name }}
            </a>
        @endforeach
    </div>
</div>






                </div>
            </div>
        </nav>

        <main>
            @yield('content')
        </main>

        <footer class="footer">
            <div class="footer-grid">
                <div class="footer-brand">
                    <h3>{{ config('app.name') }}<br>YOUR BEST COMPANY</h3>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-facebook"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                
                <div class="footer-column">
                    <h4>Product</h4>
                    <ul class="footer-links">
                        <li><a href="#download">Download</a></li>
                        <li><a href="#pricing">Pricing</a></li>
                        <li><a href="#features">Features</a></li>
                    </ul>
                </div>

                <div class="footer-column">
                    <h4>Company</h4>
                    <ul class="footer-links">
                        <li><a href="#">About</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Blog</a></li>
                    </ul>
                </div>

                <div class="footer-column">
                    <h4>Resources</h4>
                    <ul class="footer-links">
                        <li><a href="#">Support</a></li>
                        <li><a href="#">Documentation</a></li>
                        <li><a href="#">Security</a></li>
                    </ul>
                </div>

                <div class="footer-column">
                    <h4>Legal</h4>
                    <ul class="footer-links">
                        <li><a href="#">Terms</a></li>
                        <li><a href="#">Privacy</a></li>
                        <li><a href="#">Guidelines</a></li>
                    </ul>
                </div>
            </div>
        </footer>
    @endauth

    <script>
        // Mobile Menu Toggle
        document.querySelector('.mobile-menu-button')?.addEventListener('click', function() {
            document.querySelector('.nav-links').classList.toggle('active');
        });

        // Language Switcher
        document.querySelector('.language-button')?.addEventListener('click', function(e) {
            e.stopPropagation();
            document.querySelector('.language-dropdown').classList.toggle('active');
        });

        // Profile Dropdown
        document.querySelector('.profile-button')?.addEventListener('click', function(e) {
            e.stopPropagation();
            this.closest('.profile-dropdown').classList.toggle('active');
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const languageSwitcher = document.querySelector('.language-switcher');
            const profileDropdown = document.querySelector('.profile-dropdown');
            
            if (languageSwitcher && !languageSwitcher.contains(event.target)) {
                languageSwitcher.querySelector('.language-dropdown')?.classList.remove('active');
            }

            if (profileDropdown && !profileDropdown.contains(event.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        // Language Selection
// Language Selection (remove the existing code and replace with this)
document.querySelectorAll('.language-option').forEach(option => {
    option.addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = this.href;
    });
});

        // Smooth Scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const targetId = this.getAttribute('href').substring(1);
                const targetElement = document.getElementById(targetId);
                
                if (targetElement) {
                    const headerOffset = 80;
                    const elementPosition = targetElement.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>

    @stack('scripts')
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/hammer.js/2.0.8/hammer.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery-images-compare@0.2.5/build/jquery.images-compare.min.js"></script>

</body>
</html>
