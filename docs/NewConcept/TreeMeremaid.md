# Tree Concept

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
classDiagram

	WPClassHead "1" --o "1" Configuration
	class WPClassHead{
		<<abstract>>
		- long id 
		- long version
		- timestamp creation_date 
		- String type	
	}
	
	class Configuration{
		- int order
		- bool hide
		- bool read_only
		- RendererType renderer
		- Converter converter
		- Validator validators[]
	}
	
	Configuration "1" --* "0..*" Setting : settings[]
	class Setting {
		+String name
		+SettingsType type
		+Object value
	}
	
    Relation --|> WPClassHead : child_of
    Node --|> WPClassHead : child_of
    WPClassHead "1" --o "1..*" ChangeLogItem

    ChangeLog "1" o-- "0..*" ChangeLogItem: contains
    Relation --o Node : from
    Relation --o Node : to
    Node <-- Relation : type

	class IConfig {
		<<interafce>>
		getType()
		getConfig()
		
	}


    class ChangeLogItem{
      +Identity identity
      +Timestamp change_date
      +String change_owner
      +String change_description
      undo()
    }

    class Node{

    }

    class Relation{
		
    }

    class ChangeLog{
        
    }

