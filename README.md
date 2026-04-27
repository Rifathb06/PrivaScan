# PrivaScan: The Hidden Cost of "Free"

**PrivaScan** is a smart digital privacy companion designed to bridge the gap between complex legal jargon and everyday user safety. It enables users to instantly scan and summarize the Terms of Service (ToS) of any mobile application, translating confusing legal text into a simple, easy-to-read "privacy nutrition label."

## 🚀 Key Features
- **Instant ToS Summarization:** Converts lengthy legal documents into a concise 2-sentence TL;DR.
- **Privacy Nutrition Label:** A visual breakdown of data collection, tracking, and sharing risks.
- **Risk Assessment:** Automatically categorizes apps as Low, Medium, or High risk.
- **Red Flag Detection:** Highlights alarming clauses or unnecessary hardware permissions.
- **Developer-Ready:** Built to run on local XAMPP environments with a secure server-side AI bridge.

## 🛠️ Technical Stack
- **Frontend:** HTML5, Vanilla JavaScript (ES6), Tailwind CSS (CDN).
- **Backend:** PHP 8+ (Optimized for XAMPP/Apache).
- **AI Engine:** Google Gemini 2.5 Flash API.
- **Scraping:** PHP cURL & DOMDocument logic.

## 📂 Project Structure
```text
PrivaScan/
├── index.html          # Frontend: User Interface and Dashboard
├── api.php             # Backend: Scraper and Gemini API Proxy
├── config.php          # 🔒 YOUR secret API key (Git-ignored)
├── config.example.php  # Template — copy to config.php and add your key
├── .gitignore          # Keeps config.php out of version control
└── README.md           # Project Documentation
```

## ⚡ Quick Setup
1. Place all files in `htdocs/xampp/Privacy/` (or your XAMPP web root).
2. Copy `config.example.php` → `config.php` and paste your [Gemini API key](https://aistudio.google.com/app/apikey).
3. Start Apache in XAMPP and visit `http://localhost/xampp/Privacy/`.